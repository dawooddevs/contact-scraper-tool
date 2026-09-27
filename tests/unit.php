<?php

declare(strict_types=1);

/*
 * Unit tests (no database, no network): parsers, email extraction,
 * phone/URL helpers, lead conditions.
 *
 *   php tests/unit.php
 */

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/mock/generator.php';

use App\Http\HttpRequest;
use App\Http\HttpResponse;
use App\Http\BlockDetector;
use App\Http\Connection;
use App\Scraper\CampaignOptions;
use App\Scraper\EmailExtractor;
use App\Scraper\LeadFilter;
use App\Sources\Listing;
use App\Sources\Yelp;
use App\Sources\YellowPagesAu;
use App\Support\Html;
use App\Support\Phone;
use App\Support\Url;

App\Core\Settings::preload([]); // defaults only, no database

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } else {
        $failed++;
        echo "  \033[31m✗ {$name}\033[0m" . ($detail !== '' ? "\n      {$detail}" : '') . "\n";
    }
}

function eq(string $name, mixed $actual, mixed $expected): void
{
    check($name, $actual === $expected, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function response(string $url, string $body, int $status = 200, string $type = 'text/html', string $kind = 'directory', string $via = 'direct'): HttpResponse
{
    return new HttpResponse(new HttpRequest($url, $kind), $status, $body, ['content-type' => $type], $url, null, 10, $via);
}

// -------------------------------------------------------------------------
echo "\nEmail extraction\n";

$html = '<a href="mailto:Info@Example-Biz.com.au?subject=Hi">x</a>'
    . '<span data-cfemail="' . Mock::cfEncode('office@cf-biz.com') . '"></span>'
    . '<p>Write to sales [at] obf-biz [dot] com</p>'
    . '<p>json: "contact@json-biz.com"</p>'
    . '<img src="logo@2x.png"><p>example@example.com noreply@biz.com</p>'
    . '<a href="mailto:%20hello@spaced.com">x</a>'
    . '<p>abcdef0123456789abcdef01@o12345.ingest.sentry.io</p>'
    . '<p>John&#64;entity-biz.com</p>';
$emails = EmailExtractor::fromHtml($html);
check('mailto with subject & case', in_array('info@example-biz.com.au', $emails, true), implode(', ', $emails));
check('cloudflare data-cfemail', in_array('office@cf-biz.com', $emails, true));
check('[at]/[dot] obfuscation', in_array('sales@obf-biz.com', $emails, true));
check('JSON \\u0040 escape', in_array('contact@json-biz.com', $emails, true));
check('HTML entity @', in_array('john@entity-biz.com', $emails, true));
check('percent-encoded space in mailto', in_array('hello@spaced.com', $emails, true));
check('rejects image names', !in_array('logo@2x.png', $emails, true));
check('rejects placeholders', !in_array('example@example.com', $emails, true));
check('rejects noreply', !in_array('noreply@biz.com', $emails, true));
check('rejects sentry DSNs', count(array_filter($emails, static fn ($e) => str_contains($e, 'sentry'))) === 0);
eq('cloudflare decoder', EmailExtractor::decodeCloudflare(Mock::cfEncode('a.b@c.io')), 'a.b@c.io');
eq('rank prefers own domain', EmailExtractor::rank(['bob@gmail.com', 'info@joes.com.au', 'web@agency.com'], 'www.joes.com.au')[0], 'info@joes.com.au');
eq('rank prefers role address', EmailExtractor::rank(['jane@x.com', 'info@x.com'], null)[0], 'info@x.com');
check('free provider detection', EmailExtractor::isFreeProvider('a@bigpond.com') && !EmailExtractor::isFreeProvider('a@joes.com.au'));
eq('normalize rejects junk', EmailExtractor::normalize('not-an-email'), null);

// -------------------------------------------------------------------------
echo "\nPhone & URL helpers\n";

eq('AU phone clean', Phone::clean('Ph: (07) 3123 4567'), '(07) 3123 4567');
eq('AU digits national', Phone::digits('(07) 3123 4567', 'AU'), '731234567');
eq('AU digits intl', Phone::digits('+61 7 3123 4567', 'AU'), '731234567');
eq('US digits', Phone::digits('+1 (212) 555-0101', 'US'), '2125550101');
eq('rejects short numbers', Phone::clean('12'), null);
eq('normalize bare domain', Url::normalize('www.Joes.com.au/menu'), 'http://www.joes.com.au/menu');
eq('domain strips www', Url::domain('https://www.joes.com.au/x'), 'joes.com.au');
eq('social detection', Url::socialNetwork('https://m.facebook.com/joes'), 'facebook');
eq('x.com is twitter', Url::socialNetwork('https://x.com/joes'), 'twitter');
check('platform detection', Url::isPlatform('https://www.yelp.com/biz/x') && !Url::isPlatform('https://joes.com'));
eq('resolve relative', Url::resolve('https://a.com/dir/page', '../contact'), 'https://a.com/contact');
eq('resolve absolute path', Url::resolve('https://a.com/dir/page', '/about?x=1#top'), 'https://a.com/about?x=1');
eq('unwrap biz_redir', Url::unwrapRedirect('https://www.yelp.com/biz_redir?url=https%3A%2F%2Fjoes.com%2F&src=1'), 'https://joes.com/');
eq('clean drops utm', Url::clean('https://Joes.com/?utm_source=yp&id=4'), 'https://joes.com/?id=4');
check('private host blocked', !Url::isPublicHost('127.0.0.1') && !Url::isPublicHost('10.1.2.3') && !Url::isPublicHost('localhost'));
check('public IP allowed', Url::isPublicHost('93.184.216.34'));

// -------------------------------------------------------------------------
echo "\nHTML helpers\n";

$state = Html::jsonAfter('<script>window.__STATE__ = {"a":{"b":"x}y\\"z","c":[1,2,{"d":"</script>"}]}};foo()</script>', 'window.__STATE__');
eq('jsonAfter balanced with tricky strings', $state['a']['c'][2]['d'] ?? null, '</script>');
$ld = Html::jsonLd('<script type="application/ld+json">{"@graph":[{"@type":"WebSite","name":"x"},{"@type":"LocalBusiness","name":"Joe","telephone":"123"}]}</script>');
eq('JSON-LD @graph flattening', count($ld), 3);

// -------------------------------------------------------------------------
echo "\nListing normalisation\n";

$l = Listing::fromArray(['name' => ' Joe&#39;s  Pizza ', 'website' => 'https://www.facebook.com/joespizza', 'phone' => 'tel:0731234567', 'emails' => ['INFO@joes.com.au', 'bad']]);
$l->normalize();
eq('decodes & squishes name', $l->name, "Joe's Pizza");
eq('facebook moves to socials', $l->socials['facebook'] ?? null, 'https://www.facebook.com/joespizza');
eq('website cleared when social', $l->website, null);
eq('primary email picked', $l->email, 'info@joes.com.au');
check('ref generated when missing', strlen($l->ref) === 32);

// -------------------------------------------------------------------------
echo "\nYellow Pages AU parser\n";

$yp = new YellowPagesAu();
$req = $yp->searchRequest('Restaurants', 'Brisbane QLD', 2);
check('search URL', str_contains($req->url, 'clue=Restaurants') && str_contains($req->url, 'locationClue=Brisbane+QLD') && str_contains($req->url, 'pageNumber=2'), $req->url);
$result = $yp->parseSearch(response('https://www.yellowpages.com.au/search/listings', Mock::ypSearchPage('Restaurants', 'Brisbane QLD', 1)), 1);
eq('page 1 listing count (in-area only)', count($result['listings']), 25);
eq('recognized', $result['recognized'], true);
eq('has more pages', $result['has_more'], true);
eq('total results', $result['total'], 60);
$first = $result['listings'][0]->normalize();
eq('name', $first->name, 'Test Business 1');
eq('ref = listingId', $first->ref, '1000001');
eq('phone displayValue', $first->phone, '(07) 3001 1001');
eq('suburb', $first->city, 'Brisbane City');
eq('postcode', $first->postcode, '4000');
eq('details link absolute', $first->sourceUrl, 'https://www.yellowpages.com.au/qld/brisbane-city/test-business-1-1000001-listing.html');
$third = $result['listings'][2]->normalize();
eq('primary email', $third->email, 'hello3@business3.com.au');
$eleventh = $result['listings'][10]->normalize();
eq('YP redirect unwrapped', $eleventh->website, Mock::site(11));
$thirteenth = $result['listings'][12]->normalize();
eq('facebook website → socials', $thirteenth->socials['facebook'] ?? null, 'https://www.facebook.com/business13');
$last = $yp->parseSearch(response('https://www.yellowpages.com.au/search/listings', Mock::ypSearchPage('Restaurants', 'Brisbane QLD', 3)), 3);
eq('last page count', count($last['listings']), 10);
eq('no more after last page', $last['has_more'], false);

// DOM fallback (server-rendered markup without JSON state)
$legacy = '<html><body><div class="search-contact-card"><a class="listing-name" href="/qld/brisbane/legacy-cafe-12345678-listing.html">Legacy Cafe</a>'
    . '<p class="listing-address">1 Main St, Brisbane QLD 4000</p><a href="tel:0733334444">Call</a><a class="contact-email" data-email="hi@legacycafe.com.au" href="#">Email</a>'
    . '<a class="contact-url" href="https://legacycafe.com.au">Website</a></div>'
    . '<div class="search-contact-card"><a class="listing-name" href="/qld/brisbane/other-place-87654321-listing.html">Other Place</a><a href="tel:0755556666">Call</a></div></body></html>';
$dom = $yp->parseSearch(response('https://www.yellowpages.com.au/search/listings', $legacy), 1);
eq('DOM fallback count', count($dom['listings']), 2);
$d0 = $dom['listings'][0]->normalize();
eq('DOM fallback email', $d0->email, 'hi@legacycafe.com.au');
eq('DOM fallback website', $d0->website, 'https://legacycafe.com.au/');
eq('DOM fallback phone', $d0->phone, '0733334444');
eq('DOM fallback ref', $d0->ref, '12345678');

$empty = $yp->parseSearch(response('https://www.yellowpages.com.au/search/listings', '<html><body><h1>Sorry, we couldn\'t find any results for "zzz"</h1></body></html>'), 1);
eq('no-results page recognised', [$empty['no_results'], $empty['recognized']], [true, true]);
$garbage = $yp->parseSearch(response('https://www.yellowpages.com.au/search/listings', '<html><body>Loading…</body></html>'), 1);
eq('unknown page not recognised', $garbage['recognized'], false);

$detail = $yp->parseDetail(response('https://www.yellowpages.com.au/x-1000014-listing.html', Mock::ypDetailPage(1000003)), Listing::fromArray(['name' => 'Test Business 3', 'ref' => '1000003']));
eq('detail page merges email', $detail->normalize()->email, 'hello3@business3.com.au');

// -------------------------------------------------------------------------
echo "\nYelp parser\n";

$yelp = new Yelp();
$req = $yelp->searchRequest('Restaurants', 'New York, NY', 3);
check('snippet URL with offset', str_contains($req->url, '/search/snippet?') && str_contains($req->url, 'start=20'), $req->url);
$res = $yelp->parseSearch(response('https://www.yelp.com/search/snippet', Mock::yelpSnippet('Restaurants', 'New York, NY', 0), 200, 'application/json'), 1);
eq('page 1: 10 organic results, ad skipped', count($res['listings']), 10);
eq('has more', $res['has_more'], true);
$y1 = $res['listings'][0]->normalize();
eq('hydrated name', $y1->name, 'Yelp Biz 1');
eq('hydrated phone', $y1->phone, '(212) 555-1001');
eq('biz_redir website decoded', $y1->website, Mock::site(2001));
eq('categories joined', $y1->category, 'Diners, Breakfast & Brunch');
$y3 = $res['listings'][2];
eq('unhydrated placeholder name', $y3->name, 'Yelp Biz 3 New York');
eq('unhydrated flag', $y3->meta['hydrated'], false);
check('unhydrated needs detail', $yelp->needsDetail($y3, CampaignOptions::fromArray([])));
$wrapped = '<html><body><pre style="word-wrap: break-word">' . htmlspecialchars(Mock::yelpSnippet('Restaurants', 'New York, NY', 0)) . '</pre></body></html>';
eq('JSON wrapped in <pre> by JS-rendering APIs', count($yelp->parseSearch(response('https://www.yelp.com/search/snippet', $wrapped), 1)['listings']), 10);
$page3 = $yelp->parseSearch(response('https://www.yelp.com/search/snippet', Mock::yelpSnippet('Restaurants', 'New York, NY', 20), 200, 'application/json'), 3);
eq('last page count', count($page3['listings']), 5);
eq('no more after last', $page3['has_more'], false);

$bizReq = $yelp->detailRequest($y3);
eq('biz page URL', $bizReq?->url, 'https://www.yelp.com/biz/yelp-biz-3-new-york');
$filled = $yelp->parseDetail(response('https://www.yelp.com/biz/yelp-biz-3-new-york', Mock::yelpBizPage('yelp-biz-3-new-york')), $y3)->normalize();
eq('detail replaces placeholder name', $filled->name, 'Yelp Biz 3');
eq('detail phone from JSON-LD', $filled->phone, '(212) 555-1003');
eq('detail website from biz_redir', $filled->website, Mock::site(2003));
eq('detail address', [$filled->city, $filled->state, $filled->postcode], ['New York', 'NY', '10006']);

$api = $yelp->parseSearch(response('https://api.yelp.com/v3/businesses/search', json_encode(['total' => 120, 'businesses' => [[
    'id' => 'abc', 'alias' => 'joes-new-york', 'name' => "Joe's", 'display_phone' => '(212) 555-0000',
    'location' => ['display_address' => ['1 Main St', 'New York, NY 10001'], 'city' => 'New York', 'state' => 'NY', 'zip_code' => '10001', 'country' => 'US'],
    'coordinates' => ['latitude' => 40.7, 'longitude' => -74.0], 'rating' => 4.5, 'review_count' => 12, 'categories' => [['title' => 'Pizza']],
]]]), 200, 'application/json', 'api'), 1);
eq('Fusion API mapping', [$api['listings'][0]->name, $api['listings'][0]->sourceUrl, $api['has_more']], ["Joe's", 'https://www.yelp.com/biz/joes-new-york', true]);

// -------------------------------------------------------------------------
echo "\nBlock detection\n";

eq('DataDome wall', BlockDetector::inspect(response('https://www.yelp.com/', '<html><script src="https://ct.captcha-delivery.com/c.js"></script></html>', 403)), 'DataDome bot protection (HTTP 403)');
eq('Cloudflare challenge', BlockDetector::inspect(response('https://x.com/', '<html><head><title>Just a moment...</title></head></html>', 503)), 'Cloudflare challenge (HTTP 503)');
eq('429', BlockDetector::inspect(response('https://x.com/', 'slow down', 429)), 'Rate limited by the website (HTTP 429)');
eq('normal page ok', BlockDetector::inspect(response('https://x.com/', str_repeat('<div>content</div>', 20000))), null);
check('API key error explained', str_contains((string) BlockDetector::inspect(response('https://x.com/', 'Unauthorized', 401, 'text/plain', 'directory', 'scraperapi')), 'API key'));

// -------------------------------------------------------------------------
echo "\nConnection modes\n";

eq('proxy parse url form', Connection::parseProxy('http://user:p%40ss@1.2.3.4:8080'), ['proxy' => '1.2.3.4:8080', 'type' => CURLPROXY_HTTP, 'auth' => 'user:p@ss']);
eq('proxy parse colon form', Connection::parseProxy('1.2.3.4:3128:bob:secret'), ['proxy' => '1.2.3.4:3128', 'type' => CURLPROXY_HTTP, 'auth' => 'bob:secret']);
eq('proxy parse socks5', Connection::parseProxy('socks5://5.6.7.8:1080')['type'] ?? null, CURLPROXY_SOCKS5);
eq('invalid proxy', Connection::parseProxy('not a proxy'), null);

$target = 'https://www.yelp.com/search/snippet?find_desc=Pizza&start=10';
[$u] = Connection::make('scraperapi', ['scraperapi_key' => 'K1', 'render_js' => true, 'premium' => true, 'geo_target' => true])->prepare($target, 'US');
parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
eq('ScraperAPI URL', [parse_url($u, PHP_URL_HOST), $q['api_key'], $q['url'], $q['country_code'], $q['render'], $q['premium']], ['api.scraperapi.com', 'K1', $target, 'us', 'true', 'true']);
[$u] = Connection::make('scrapingbee', ['scrapingbee_key' => 'K2', 'premium' => true, 'render_js' => false, 'geo_target' => true])->prepare($target, 'AU');
parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
eq('ScrapingBee URL', [$q['api_key'], $q['url'], $q['render_js'], $q['premium_proxy'], $q['country_code']], ['K2', $target, 'false', 'true', 'au']);
[$u] = Connection::make('zenrows', ['zenrows_key' => 'K3', 'render_js' => true, 'premium' => false])->prepare($target, 'US');
parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
eq('ZenRows URL', [$q['apikey'], $q['url'], $q['js_render'], isset($q['proxy_country'])], ['K3', $target, 'true', false]);
[$u] = Connection::make('scrapedo', ['scrapedo_key' => 'K4', 'premium' => true, 'geo_target' => false])->prepare($target, 'US');
parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
eq('Scrape.do URL', [$q['token'], $q['url'], $q['super'], isset($q['geoCode'])], ['K4', $target, 'true', false]);
[$u] = Connection::make('custom', ['custom_template' => 'https://proxy.example/fetch?u={url}&c={country}'])->prepare($target, 'AU');
eq('custom template', $u, 'https://proxy.example/fetch?u=' . rawurlencode($target) . '&c=au');
[$u, $opts] = Connection::make('proxy', ['proxies' => "# comment\nhttp://u:p@9.9.9.9:8000"])->prepare($target, 'US');
eq('proxy mode keeps URL and sets curl proxy', [$u, $opts[CURLOPT_PROXY], $opts[CURLOPT_PROXYUSERPWD]], [$target, '9.9.9.9:8000', 'u:p']);
try {
    Connection::make('scraperapi', ['scraperapi_key' => ''])->prepare($target, 'US');
    check('missing key throws config error', false);
} catch (App\Api\ApiException $e) {
    eq('missing key throws config error', $e->errorCode, 'config');
}

// -------------------------------------------------------------------------
echo "\nLead conditions\n";

$opts = CampaignOptions::fromArray(['require' => ['email' => true, 'phone' => true, 'website' => true], 'collect' => ['email' => false]]);
check('required fields are collected', $opts->collect['email'] && $opts->collect['website']);
$lead = Listing::fromArray(['name' => 'X', 'phone' => '0731234567', 'website' => 'https://joes.com']);
$lead->normalize();
eq('missing email rejected', LeadFilter::evaluate($lead, $opts), 'Missing email');
check('can still qualify via crawl', LeadFilter::canStillQualify($lead, $opts, ['email']));
$noSite = Listing::fromArray(['name' => 'Y', 'phone' => '0731234567']);
$noSite->normalize();
eq('two missing fields message', LeadFilter::evaluate($noSite, $opts), 'Missing email and website');
check('cannot qualify without website source', !LeadFilter::canStillQualify($noSite, $opts, ['email']));
$any = CampaignOptions::fromArray(['require' => ['email' => true, 'phone' => true], 'match' => 'any']);
eq('match any passes with one', LeadFilter::evaluate($noSite, $any), null);
$rating = CampaignOptions::fromArray(['min_rating' => 4]);
$low = Listing::fromArray(['name' => 'Z', 'rating' => 3.5]);
eq('rating filter', LeadFilter::evaluate($low, $rating), 'Rating below 4');
$opts2 = CampaignOptions::fromArray(['max_pages' => 500, 'max_leads' => -5]);
eq('options clamped', [$opts2->maxPages, $opts2->maxLeads], [100, 0]);

echo "\n" . ($failed ? "\033[31m{$failed} failed\033[0m, " : '') . "\033[32m{$passed} passed\033[0m\n";
exit($failed ? 1 : 0);
