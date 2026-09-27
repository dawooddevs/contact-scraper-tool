<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\App;
use App\Core\Settings;
use App\Support\Url;
use CurlHandle;

/**
 * cURL based HTTP client with parallel execution (curl_multi), realistic
 * browser headers, per-source cookie jars, size limits, SSRF protection for
 * business websites and bot-protection detection for directory pages.
 */
final class Fetcher
{
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:141.0) Gecko/20100101 Firefox/141.0',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
    ];

    private const MAX_BYTES = [
        'directory' => 8 * 1024 * 1024,
        'website' => 2 * 1024 * 1024,
        'api' => 4 * 1024 * 1024,
    ];

    /** @var array<string, string> sticky user agent per source for the lifetime of the process */
    private static array $sessionAgents = [];

    public function __construct(private readonly ?Connection $connectionOverride = null)
    {
    }

    public function fetch(HttpRequest $request): HttpResponse
    {
        return $this->fetchMany([$request], 1)[0];
    }

    /**
     * Execute requests with at most $concurrency in flight. Responses are
     * returned in the same order as the requests.
     *
     * @param HttpRequest[] $requests
     * @return HttpResponse[]
     */
    public function fetchMany(array $requests, int $concurrency = 6): array
    {
        $requests = array_values($requests);
        $results = [];
        $queue = array_keys($requests);
        $multi = curl_multi_init();
        $active = []; // (int) handle id => [index, handle, state]

        $start = function (int $index) use ($requests, $multi, &$active, &$results): void {
            $request = $requests[$index];
            try {
                [$handle, $state] = $this->buildHandle($request);
            } catch (\Throwable $e) {
                if ($e instanceof \App\Api\ApiException) {
                    throw $e; // configuration problems must surface
                }
                $results[$index] = new HttpResponse($request, 0, '', [], $request->url, $e->getMessage(), 0, 'n/a');
                return;
            }
            if ($handle === null) {
                $results[$index] = $state; // pre-flight rejection (HttpResponse)
                return;
            }
            curl_multi_add_handle($multi, $handle);
            $active[spl_object_id($handle)] = [$index, $handle, $state];
        };

        try {
            while ($queue && count($active) < max(1, $concurrency)) {
                $start(array_shift($queue));
            }

            do {
                $status = curl_multi_exec($multi, $running);
                if ($running) {
                    curl_multi_select($multi, 0.5);
                }
                while ($info = curl_multi_info_read($multi)) {
                    $handle = $info['handle'];
                    $id = spl_object_id($handle);
                    if (!isset($active[$id])) {
                        continue;
                    }
                    [$index, , $state] = $active[$id];
                    unset($active[$id]);
                    $results[$index] = $this->finish($requests[$index], $handle, $state, (int) $info['result']);
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                    while ($queue && count($active) < max(1, $concurrency)) {
                        $start(array_shift($queue));
                    }
                }
            } while (($running > 0 || $active) && $status === CURLM_OK);
        } finally {
            foreach ($active as [, $handle]) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }

        // Anything still missing (should not happen) becomes an error response.
        foreach ($requests as $i => $request) {
            if (!isset($results[$i])) {
                $results[$i] = new HttpResponse($request, 0, '', [], $request->url, 'Request did not complete', 0, 'n/a');
            }
        }
        ksort($results);
        return array_values($results);
    }

    private function connectionFor(HttpRequest $request): Connection
    {
        if ($this->connectionOverride !== null) {
            return $this->connectionOverride;
        }
        return match ($request->kind) {
            'website' => Connection::forWebsites(),
            'api' => new Connection('direct'),
            default => Connection::forSource($request->sourceKey),
        };
    }

    /** @return array{0: ?CurlHandle, 1: mixed} */
    private function buildHandle(HttpRequest $request): array
    {
        $connection = $this->connectionFor($request);
        $target = $request->url;
        $host = Url::host($target);
        if ($host === null || !preg_match('~^https?://~i', $target)) {
            return [null, new HttpResponse($request, 0, '', [], $target, 'Invalid URL', 0, $connection->mode)];
        }
        if ($request->kind === 'website' && !App::config('allow_private_network', false) && !Url::isPublicHost($host)) {
            return [null, new HttpResponse($request, 0, '', [], $target, 'Blocked: private network address', 0, $connection->mode)];
        }

        [$url, $extra] = $connection->prepare($target, $request->country);
        $isApi = $connection->isApi();

        $timeout = $request->timeout ?? match (true) {
            $request->kind === 'website' => Settings::int('enrich.timeout', 5, 60),
            $isApi => Settings::int('scrape.api_timeout', 20, 180),
            default => Settings::int('scrape.timeout', 5, 120),
        };

        $state = (object) [
            'body' => '',
            'headers' => [],
            'bytes' => 0,
            'max' => self::MAX_BYTES[$request->kind] ?? self::MAX_BYTES['directory'],
            'truncated' => false,
            'started' => microtime(true),
            'via' => $connection->mode,
            'isApi' => $isApi,
        ];

        $headers = $isApi ? [] : $this->browserHeaders($request);
        foreach ($request->headers as $name => $value) {
            $headers[$name] = $value;
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_AUTOREFERER => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use ($state): int {
                $trim = trim($line);
                if (stripos($trim, 'HTTP/') === 0) {
                    $state->headers = []; // new response in a redirect chain
                } elseif (str_contains($trim, ':')) {
                    [$k, $v] = explode(':', $trim, 2);
                    $state->headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($state): int {
                $len = strlen($chunk);
                if ($state->bytes + $len > $state->max) {
                    $state->body .= substr($chunk, 0, max(0, $state->max - $state->bytes));
                    $state->bytes = $state->max;
                    $state->truncated = true;
                    return -1; // abort transfer; we keep what we have
                }
                $state->body .= $chunk;
                $state->bytes += $len;
                return $len;
            },
        ];

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
            $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        $version = curl_version();
        if (!$isApi && defined('CURL_HTTP_VERSION_2TLS') && ($version['features'] & CURL_VERSION_HTTP2)) {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        }

        if ($request->kind === 'directory' && !$isApi && $request->sourceKey) {
            $jar = App::storagePath('cookies/' . preg_replace('/[^a-z0-9_]/i', '', $request->sourceKey) . '.txt');
            $options[CURLOPT_COOKIEFILE] = $jar;
            $options[CURLOPT_COOKIEJAR] = $jar;
        } else {
            $options[CURLOPT_COOKIEFILE] = ''; // in-memory cookies for redirects
        }

        if ($request->kind === 'website' && !$isApi) {
            // Small business sites often have expired/misconfigured certificates;
            // we only read public pages, so don't lose the lead over it.
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        foreach ($extra as $k => $v) {
            $options[$k] = $v;
        }
        curl_setopt_array($ch, $options);

        return [$ch, $state];
    }

    private function finish(HttpRequest $request, CurlHandle $ch, object $state, int $curlResult): HttpResponse
    {
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $ip = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $error = null;

        if ($curlResult !== CURLE_OK && !($state->truncated && $curlResult === CURLE_WRITE_ERROR)) {
            $error = self::describeCurlError($curlResult, curl_error($ch));
        }
        if ($state->isApi) {
            $finalUrl = $request->url; // never expose API keys in stored URLs
        }
        if ($request->kind === 'website' && $ip !== '' && !App::config('allow_private_network', false)
            && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return new HttpResponse($request, 0, '', [], $finalUrl, 'Blocked: redirected to a private network address', 0, $state->via);
        }

        $response = new HttpResponse(
            $request,
            $status,
            $state->body,
            $state->headers,
            $finalUrl !== '' ? $finalUrl : $request->url,
            $error,
            (int) round((microtime(true) - $state->started) * 1000),
            $state->via,
        );

        if ($request->kind === 'directory') {
            $response->blockReason = BlockDetector::inspect($response);
        } elseif ($state->isApi && $status >= 400) {
            $response->blockReason = BlockDetector::apiError($response);
        }
        return $response;
    }

    private static function describeCurlError(int $code, string $message): string
    {
        return match ($code) {
            CURLE_OPERATION_TIMEDOUT => 'Timed out',
            CURLE_COULDNT_RESOLVE_HOST => 'Domain does not resolve',
            CURLE_COULDNT_CONNECT => 'Could not connect',
            35, 51, 58, 59, 60 => 'SSL/TLS error', // connect / peer verification / cert problems
            CURLE_TOO_MANY_REDIRECTS => 'Too many redirects',
            CURLE_COULDNT_RESOLVE_PROXY => 'Proxy host does not resolve',
            default => 'Network error: ' . ($message !== '' ? $message : 'cURL code ' . $code),
        };
    }

    private function browserHeaders(HttpRequest $request): array
    {
        $key = $request->sourceKey ?? $request->kind;
        if (!isset(self::$sessionAgents[$key])) {
            self::$sessionAgents[$key] = self::USER_AGENTS[array_rand(self::USER_AGENTS)];
        }
        $ua = self::$sessionAgents[$key];
        $lang = match (strtoupper((string) $request->country)) {
            'AU' => 'en-AU,en;q=0.9',
            'GB', 'UK' => 'en-GB,en;q=0.9',
            'CA' => 'en-CA,en;q=0.9',
            'NZ' => 'en-NZ,en;q=0.9',
            default => 'en-US,en;q=0.9',
        };
        $headers = [
            'User-Agent' => $ua,
            'Accept' => $request->expectJson
                ? 'application/json, text/plain, */*'
                : 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => $lang,
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => $request->expectJson ? 'empty' : 'document',
            'Sec-Fetch-Mode' => $request->expectJson ? 'cors' : 'navigate',
            'Sec-Fetch-Site' => $request->expectJson ? 'same-origin' : 'none',
        ];
        if (str_contains($ua, 'Chrome/')) {
            preg_match('~Chrome/(\d+)~', $ua, $m);
            $v = $m[1] ?? '139';
            $brand = str_contains($ua, 'Edg/') ? 'Microsoft Edge' : 'Google Chrome';
            $headers['sec-ch-ua'] = "\"Not;A=Brand\";v=\"99\", \"{$brand}\";v=\"{$v}\", \"Chromium\";v=\"{$v}\"";
            $headers['sec-ch-ua-mobile'] = '?0';
            $headers['sec-ch-ua-platform'] = str_contains($ua, 'Windows') ? '"Windows"' : (str_contains($ua, 'Mac') ? '"macOS"' : '"Linux"');
        }
        if ($request->expectJson) {
            unset($headers['Upgrade-Insecure-Requests']);
        }
        return $headers;
    }
}
