<?php

declare(strict_types=1);

namespace App\Http;

use App\Api\ApiException;
use App\Core\Settings;

/**
 * How a request reaches its target: directly, through a proxy, or through a
 * scraping API (ScraperAPI, ScrapingBee, ZenRows, Scrape.do, custom).
 *
 * Scraping APIs handle IP rotation, CAPTCHAs and JavaScript rendering, which
 * is usually required for Yellow Pages and Yelp from a shared-hosting IP.
 */
final class Connection
{
    public const MODES = [
        'direct' => 'Direct (server IP)',
        'proxy' => 'HTTP/SOCKS proxy list',
        'scraperapi' => 'ScraperAPI',
        'scrapingbee' => 'ScrapingBee',
        'zenrows' => 'ZenRows',
        'scrapedo' => 'Scrape.do',
        'custom' => 'Custom API URL template',
    ];

    public function __construct(
        public readonly string $mode,
        public readonly bool $renderJs = false,
        public readonly bool $premium = false,
        public readonly bool $geoTarget = true,
        private readonly array $overrides = [],
    ) {
    }

    /** Connection for a directory source, honouring the per-source override. */
    public static function forSource(?string $sourceKey): self
    {
        $mode = (string) Settings::get('connection.mode', 'direct');
        if ($sourceKey !== null) {
            $override = Settings::source($sourceKey)['connection'];
            if ($override !== '' && $override !== 'default' && isset(self::MODES[$override])) {
                $mode = $override;
            }
        }
        return self::make($mode);
    }

    public static function forWebsites(): self
    {
        if (Settings::get('connection.website_mode', 'direct') === 'same') {
            return self::make((string) Settings::get('connection.mode', 'direct'));
        }
        return new self('direct');
    }

    public static function make(string $mode, array $overrides = []): self
    {
        if (!isset(self::MODES[$mode])) {
            $mode = 'direct';
        }
        return new self(
            $mode,
            (bool) ($overrides['render_js'] ?? Settings::get('connection.render_js')),
            (bool) ($overrides['premium'] ?? Settings::get('connection.premium')),
            (bool) ($overrides['geo_target'] ?? Settings::get('connection.geo_target')),
            $overrides,
        );
    }

    public function isApi(): bool
    {
        return !in_array($this->mode, ['direct', 'proxy'], true);
    }

    private function setting(string $key): string
    {
        return trim((string) ($this->overrides[$key] ?? Settings::get('connection.' . $key, '')));
    }

    /**
     * Rewrite a target URL into the URL actually requested.
     *
     * @return array{0: string, 1: array} [url, extra curl options]
     */
    public function prepare(string $targetUrl, ?string $country): array
    {
        $cc = $this->geoTarget && $country ? strtolower($country) : null;
        $enc = rawurlencode($targetUrl);

        switch ($this->mode) {
            case 'scraperapi':
                $key = $this->requireKey('scraperapi_key', 'ScraperAPI');
                $q = ['api_key' => $key, 'url' => $targetUrl];
                if ($cc) {
                    $q['country_code'] = $cc;
                }
                if ($this->renderJs) {
                    $q['render'] = 'true';
                }
                if ($this->premium) {
                    $q['premium'] = 'true';
                }
                return ['https://api.scraperapi.com/?' . http_build_query($q), []];

            case 'scrapingbee':
                $key = $this->requireKey('scrapingbee_key', 'ScrapingBee');
                $q = ['api_key' => $key, 'url' => $targetUrl, 'render_js' => $this->renderJs ? 'true' : 'false'];
                if ($this->premium) {
                    $q['premium_proxy'] = 'true';
                    if ($cc) {
                        $q['country_code'] = $cc;
                    }
                }
                return ['https://app.scrapingbee.com/api/v1/?' . http_build_query($q), []];

            case 'zenrows':
                $key = $this->requireKey('zenrows_key', 'ZenRows');
                $q = ['apikey' => $key, 'url' => $targetUrl];
                if ($this->renderJs) {
                    $q['js_render'] = 'true';
                }
                if ($this->premium) {
                    $q['premium_proxy'] = 'true';
                    if ($cc) {
                        $q['proxy_country'] = $cc;
                    }
                }
                return ['https://api.zenrows.com/v1/?' . http_build_query($q), []];

            case 'scrapedo':
                $key = $this->requireKey('scrapedo_key', 'Scrape.do');
                $q = ['token' => $key, 'url' => $targetUrl];
                if ($cc) {
                    $q['geoCode'] = $cc;
                }
                if ($this->renderJs) {
                    $q['render'] = 'true';
                }
                if ($this->premium) {
                    $q['super'] = 'true';
                }
                return ['https://api.scrape.do/?' . http_build_query($q), []];

            case 'custom':
                $template = $this->setting('custom_template');
                if ($template === '' || (!str_contains($template, '{url}') && !str_contains($template, '{url_raw}'))) {
                    throw new ApiException('The custom API URL template must contain {url}. Update it in Settings → Connection.', 422, [], 'config');
                }
                $url = strtr($template, [
                    '{url}' => $enc,
                    '{url_raw}' => $targetUrl,
                    '{country}' => $cc ?? '',
                ]);
                return [$url, []];

            case 'proxy':
                $proxy = $this->pickProxy();
                if ($proxy === null) {
                    throw new ApiException('Proxy mode is selected but the proxy list is empty. Add proxies in Settings → Connection.', 422, [], 'config');
                }
                $opts = [CURLOPT_PROXY => $proxy['proxy'], CURLOPT_PROXYTYPE => $proxy['type']];
                if ($proxy['auth'] !== null) {
                    $opts[CURLOPT_PROXYUSERPWD] = $proxy['auth'];
                }
                if (!Settings::bool('connection.proxy_verify_ssl')) {
                    $opts[CURLOPT_SSL_VERIFYPEER] = false;
                    $opts[CURLOPT_SSL_VERIFYHOST] = 0;
                }
                return [$targetUrl, $opts];

            default:
                return [$targetUrl, []];
        }
    }

    private function requireKey(string $key, string $label): string
    {
        $value = $this->setting($key);
        if ($value === '') {
            throw new ApiException("{$label} is selected but no API key is saved. Add it in Settings → Connection.", 422, [], 'config');
        }
        return $value;
    }

    /** @return array{proxy: string, type: int, auth: ?string}|null */
    public function pickProxy(): ?array
    {
        $lines = preg_split('/[\r\n,]+/', $this->setting('proxies')) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn ($l) => $l !== '' && $l[0] !== '#'));
        if (!$lines) {
            return null;
        }
        return self::parseProxy($lines[random_int(0, count($lines) - 1)]);
    }

    /**
     * Accepts "http://user:pass@host:port", "socks5://host:port",
     * "host:port" and "host:port:user:pass".
     *
     * @return array{proxy: string, type: int, auth: ?string}|null
     */
    public static function parseProxy(string $line): ?array
    {
        $line = trim($line);
        $type = CURLPROXY_HTTP;
        $auth = null;
        if (preg_match('~^(https?|socks5h?|socks4a?)://~i', $line, $m)) {
            $scheme = strtolower($m[1]);
            $type = match ($scheme) {
                'socks5' => CURLPROXY_SOCKS5,
                'socks5h' => CURLPROXY_SOCKS5_HOSTNAME,
                'socks4' => CURLPROXY_SOCKS4,
                'socks4a' => CURLPROXY_SOCKS4A,
                'https' => defined('CURLPROXY_HTTPS') ? CURLPROXY_HTTPS : CURLPROXY_HTTP,
                default => CURLPROXY_HTTP,
            };
            $rest = substr($line, strlen($m[0]));
            if (str_contains($rest, '@')) {
                [$creds, $rest] = explode('@', $rest, 2);
                $auth = rawurldecode($creds);
            }
            $hostPort = rtrim($rest, '/');
        } else {
            $parts = explode(':', $line);
            if (count($parts) === 4) {
                $hostPort = $parts[0] . ':' . $parts[1];
                $auth = $parts[2] . ':' . $parts[3];
            } else {
                $hostPort = $line;
            }
        }
        if (!preg_match('/^[A-Za-z0-9.\-\[\]:]+:\d+$/', $hostPort)) {
            return null;
        }
        return ['proxy' => $hostPort, 'type' => $type, 'auth' => $auth];
    }

    public function label(): string
    {
        $label = self::MODES[$this->mode];
        if ($this->isApi()) {
            $flags = [];
            if ($this->renderJs) {
                $flags[] = 'JS rendering';
            }
            if ($this->premium) {
                $flags[] = 'premium';
            }
            if ($flags) {
                $label .= ' (' . implode(', ', $flags) . ')';
            }
        }
        return $label;
    }
}
