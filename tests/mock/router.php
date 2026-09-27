<?php

declare(strict_types=1);

/*
 * Mock "internet" for integration tests. Run with:
 *   php -S 127.0.0.1:8099 tests/mock/router.php
 *
 * - /api?url=<target>  behaves like a scraping API (custom connection mode)
 *   and serves synthetic Yellow Pages AU / Yelp pages for that target.
 * - /site/b<N>/...     synthetic business websites with emails hidden in
 *   different ways (mailto, contact page, Cloudflare, obfuscation, none, 500).
 *
 * Everything is deterministic so tests can compute the exact expected result.
 */

require __DIR__ . '/generator.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if ($uri === '/api') {
    $target = (string) ($_GET['url'] ?? '');
    $parts = parse_url($target);
    $host = $parts['host'] ?? '';
    $path = $parts['path'] ?? '/';
    parse_str($parts['query'] ?? '', $q);

    if ($host === 'www.yellowpages.com.au' && $path === '/search/listings') {
        if (($q['clue'] ?? '') === 'blockme') {
            http_response_code(403);
            header('Content-Type: text/html');
            echo '<html><head><title>Access denied</title></head><body><script src="https://ct.captcha-delivery.com/c.js"></script>Please verify you are human</body></html>';
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo Mock::ypSearchPage((string) ($q['clue'] ?? ''), (string) ($q['locationClue'] ?? ''), max(1, (int) ($q['pageNumber'] ?? 1)));
        return;
    }
    if ($host === 'www.yellowpages.com.au' && preg_match('/-(\d+)-listing\.html$/', $path, $m)) {
        header('Content-Type: text/html; charset=utf-8');
        echo Mock::ypDetailPage((int) $m[1]);
        return;
    }
    if ($host === 'www.yelp.com' && $path === '/search/snippet') {
        header('Content-Type: application/json');
        echo Mock::yelpSnippet((string) ($q['find_desc'] ?? ''), (string) ($q['find_loc'] ?? ''), (int) ($q['start'] ?? 0));
        return;
    }
    if ($host === 'www.yelp.com' && preg_match('~^/biz/([^/]+)$~', $path, $m)) {
        header('Content-Type: text/html; charset=utf-8');
        echo Mock::yelpBizPage(rawurldecode($m[1]));
        return;
    }
    if ($host === 'api.ipify.org') {
        header('Content-Type: application/json');
        echo '{"ip":"203.0.113.7"}';
        return;
    }
    http_response_code(404);
    echo 'not found: ' . htmlspecialchars($target);
    return;
}

if (preg_match('~^/site/b(\d+)(/.*)?$~', $uri, $m)) {
    Mock::website((int) $m[1], $m[2] ?? '/');
    return;
}

http_response_code(404);
echo 'mock: not found';
