<?php

declare(strict_types=1);

/**
 * Deterministic synthetic data for Yellow Pages AU, Yelp and business
 * websites. Shared by the mock server and the tests (to compute the exact
 * expected outcome of a campaign).
 */
final class Mock
{
    public const BASE = 'http://127.0.0.1:8099';
    public const YP_TOTAL = 60;
    public const YP_PER_PAGE = 25;
    public const YELP_TOTAL = 25;

    // ------------------------------------------------------------ helpers

    public static function site(int $n): string
    {
        return self::BASE . '/site/b' . $n . '/';
    }

    /** 0 mailto on home · 1 contact page · 2 Cloudflare · 3 obfuscated on about page · 4 no email · 5 HTTP 500 */
    public static function siteType(int $n): int
    {
        return $n % 6;
    }

    public static function siteHasEmail(int $n): bool
    {
        return self::siteType($n) <= 3;
    }

    public static function siteEmail(int $n): string
    {
        return ['info', 'sales', 'office', 'enquiries'][self::siteType($n)] . "@b{$n}-site.com.au";
    }

    // ------------------------------------------------------- yellow pages

    public static function ypBusiness(int $i): array
    {
        $website = null;
        if ($i % 5 !== 4) {
            if ($i % 13 === 0) {
                $website = "https://www.facebook.com/business{$i}";
            } elseif ($i % 11 === 0) {
                $website = 'https://www.yellowpages.com.au/redirect?url=' . rawurlencode(self::site($i)) . '&type=website';
            } else {
                $website = self::site($i);
            }
        }
        return [
            'listingId' => (string) (1000000 + $i),
            'name' => "Test Business {$i}",
            'detailsLink' => "/qld/brisbane-city/test-business-{$i}-" . (1000000 + $i) . '-listing.html',
            'callContactNumber' => $i % 7 === 0 ? null : [
                'displayValue' => sprintf('(07) 3%03d %04d', $i % 1000, 1000 + $i),
                'value' => sprintf('073%03d%04d', $i % 1000, 1000 + $i),
                'type' => 'PHONE',
            ],
            'primaryEmail' => $i % 3 === 0 ? "hello{$i}@business{$i}.com.au" : null,
            'website' => $website,
            'primaryAddress' => [
                'addressLine' => "{$i} Queen Street",
                'suburb' => 'Brisbane City',
                'state' => 'QLD',
                'postCode' => '4000',
                'latitude' => -27.4698 + $i / 10000,
                'longitude' => 153.0251 + $i / 10000,
                'mappable' => true,
            ],
            'category' => ['name' => 'Restaurants'],
            'averageRating' => $i % 4 === 0 ? null : 3 + ($i % 5) / 2.5,
            'numberOfReviews' => $i % 4 === 0 ? 0 : $i * 3,
        ];
    }

    public static function ypHasWebsite(int $i): bool
    {
        return $i % 5 !== 4 && $i % 13 !== 0;
    }

    /** Would listing $i qualify when email, phone and website are all required? */
    public static function ypQualifiesAll(int $i): bool
    {
        $b = self::ypBusiness($i);
        $hasWebsite = self::ypHasWebsite($i);
        // Every reachable mock website shows a tel: link, which fills a missing phone.
        $hasPhone = $b['callContactNumber'] !== null || ($hasWebsite && self::siteType($i) !== 5);
        $hasEmail = $b['primaryEmail'] !== null || ($hasWebsite && self::siteHasEmail($i));
        return $hasPhone && $hasWebsite && $hasEmail;
    }

    public static function ypSearchPage(string $clue, string $location, int $page): string
    {
        $from = ($page - 1) * self::YP_PER_PAGE + 1;
        $to = min(self::YP_TOTAL, $page * self::YP_PER_PAGE);
        $rows = [];
        for ($i = $from; $i <= $to; $i++) {
            $rows[] = self::ypBusiness($i);
        }
        $state = [
            'model' => [
                'searchParams' => ['clue' => $clue, 'locationClue' => $location],
                'inAreaResultViews' => $rows,
                'outOfAreaResultViews' => [self::ypBusiness(900), self::ypBusiness(901)],
                'pagination' => ['currentPage' => $page, 'lastPage' => (int) ceil(self::YP_TOTAL / self::YP_PER_PAGE), 'totalResults' => self::YP_TOTAL],
            ],
            'session' => ['authenticated' => false],
        ];
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        return "<!DOCTYPE html><html><head><title>{$clue} in {$location} | Yellow Pages®</title>"
            . '<script src="/assets/vendor.js"></script></head><body><div id="app"><header>Yellow Pages</header>'
            . '<a href="/search/listings?clue=x&amp;pageNumber=' . ($page + 1) . '">Next</a></div>'
            . '<script>window.__INITIAL_STATE__ = ' . $json . ';window.__CONFIG__ = {"env":"prod"};</script>'
            . '<footer>Contact <a href="mailto:support@yellowpages.com.au">support</a></footer></body></html>';
    }

    public static function ypDetailPage(int $id): string
    {
        $i = $id - 1000000;
        $state = ['model' => ['listing' => self::ypBusiness($i)]];
        return '<html><head><title>Test Business</title></head><body><script>window.__INITIAL_STATE__ = '
            . json_encode($state, JSON_UNESCAPED_SLASHES) . ';</script></body></html>';
    }

    // ----------------------------------------------------------------- yelp

    public static function yelpBusiness(int $j): array
    {
        $n = 2000 + $j;
        return [
            'id' => "yelp{$j}xyz",
            'alias' => "yelp-biz-{$j}-new-york",
            'name' => "Yelp Biz {$j}",
            'hydrated' => $j % 4 !== 3,
            'phone' => $j % 6 === 0 ? null : '(212) 555-' . (1000 + $j),
            'website' => $j % 5 === 4 ? null : self::site($n),
            'site' => $n,
        ];
    }

    public static function yelpQualifiesAll(int $j): bool
    {
        $b = self::yelpBusiness($j);
        $hasPhone = $b['phone'] !== null || ($b['website'] !== null && self::siteType($b['site']) !== 5);
        return $hasPhone && $b['website'] !== null && self::siteHasEmail($b['site']);
    }

    public static function yelpSnippet(string $desc, string $loc, int $start): string
    {
        $items = [['searchResultLayoutType' => 'separator', 'props' => ['text' => 'All Results']]];
        $items[] = ['bizId' => 'ad' . $start, 'searchResultLayoutType' => 'iaResult', 'searchResultBusiness' => ['name' => 'Sponsored Place', 'alias' => 'sponsored-place', 'isAd' => true, 'businessUrl' => '/adredir?x=1']];
        for ($j = $start + 1; $j <= min(self::YELP_TOTAL, $start + 10); $j++) {
            $b = self::yelpBusiness($j);
            if (!$b['hydrated']) {
                $items[] = ['bizId' => $b['id'], 'business_id' => $b['id'], 'alias' => $b['alias'], 'searchResultLayoutType' => 'iaResult', 'searchResultBusiness' => null];
                continue;
            }
            $biz = [
                'name' => $b['name'],
                'alias' => $b['alias'],
                'businessUrl' => '/biz/' . $b['alias'] . '?osq=' . rawurlencode($desc),
                'phone' => $b['phone'],
                'formattedAddress' => "{$j} Broadway",
                'neighborhoods' => ['Financial District'],
                'rating' => 4.0 + ($j % 3) / 2,
                'reviewCount' => 10 * $j,
                'categories' => [['title' => 'Diners', 'url' => '/search?cflt=diners'], ['title' => 'Breakfast & Brunch']],
                'isAd' => false,
            ];
            if ($b['website'] !== null) {
                $biz['website'] = ['href' => '/biz_redir?url=' . rawurlencode($b['website']) . '&website_link_type=website&src_bizid=' . $b['id'], 'linkText' => 'b' . $b['site'] . '.example'];
            }
            $items[] = ['bizId' => $b['id'], 'searchResultLayoutType' => 'iaResult', 'searchResultBusiness' => $biz];
        }
        return json_encode([
            'searchPageProps' => [
                'mainContentComponentsListProps' => $items,
                'paginationInfo' => ['totalResults' => self::YELP_TOTAL, 'resultsPerPage' => 10, 'startResult' => $start],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    public static function yelpBizPage(string $alias): string
    {
        if (!preg_match('/^yelp-biz-(\d+)-/', $alias, $m)) {
            http_response_code(404);
            return 'not found';
        }
        $b = self::yelpBusiness((int) $m[1]);
        $ld = [
            '@context' => 'https://schema.org',
            '@type' => 'Restaurant',
            'name' => $b['name'],
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => $m[1] . ' Broadway', 'addressLocality' => 'New York', 'addressRegion' => 'NY', 'postalCode' => '10006', 'addressCountry' => 'US'],
            'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => 4.2, 'reviewCount' => 88],
        ];
        if ($b['phone'] !== null) {
            $ld['telephone'] = $b['phone'];
        }
        $html = '<html><head><title>' . htmlspecialchars($b['name']) . ' - New York - Yelp</title>'
            . '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_SLASHES) . '</script></head><body>';
        if ($b['website'] !== null) {
            $html .= '<section><p>Business website</p><p><a href="/biz_redir?url=' . rawurlencode($b['website']) . '&amp;cachebuster=1&amp;website_link_type=website&amp;src_bizid=' . $b['id'] . '">b' . $b['site'] . '.example</a></p></section>';
        }
        return $html . '</body></html>';
    }

    // ------------------------------------------------------------- websites

    public static function website(int $n, string $path): void
    {
        $type = self::siteType($n);
        if ($type === 5) {
            http_response_code(500);
            echo 'Internal Server Error';
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        $email = self::siteEmail($n);
        $nav = '<nav><a href="/site/b' . $n . '/">Home</a> <a href="/site/b' . $n . '/menu">Menu</a> '
            . '<a href="/site/b' . $n . '/about-us">About</a> <a href="/site/b' . $n . '/contact-us">Contact Us</a></nav>';
        $head = '<html><head><title>Business ' . $n . '</title><link rel="icon" href="logo@2x.png"></head><body>' . $nav;
        $foot = '<footer><img src="/img/logo@2x.png" alt=""> &copy; 2026</footer></body></html>';
        $path = rtrim($path, '/') ?: '/';

        if ($path === '/') {
            $body = '<h1>Welcome to business ' . $n . '</h1><p>Call us on <a href="tel:+61730001234">(07) 3000 1234</a></p>';
            if ($type === 0) {
                $body .= '<p><a href="mailto:' . $email . '?subject=Hello">Email us</a></p><a href="https://www.facebook.com/b' . $n . 'page">Facebook</a> <a href="https://www.facebook.com/sharer/sharer.php?u=x">Share</a>';
            } elseif ($type === 2) {
                $body .= '<p><a href="/cdn-cgi/l/email-protection" class="__cf_email__" data-cfemail="' . self::cfEncode($email) . '">[email&#160;protected]</a></p>';
            } elseif ($type === 4) {
                $body .= '<p>No email here. Placeholder: example@example.com, image sprite@2x.png</p><a href="https://instagram.com/b' . $n . '">Instagram</a> <a href="https://www.linkedin.com/company/b' . $n . '/">LinkedIn</a>';
            }
            echo $head . $body . $foot;
            return;
        }
        if ($path === '/contact-us') {
            echo $head . '<h1>Contact</h1>' . ($type === 1 ? '<p>Email: ' . $email . '</p>' : '<form><input name="email"></form>') . $foot;
            return;
        }
        if ($path === '/about-us') {
            $text = $type === 3 ? str_replace(['@', '.'], [' [at] ', ' [dot] '], $email) : 'We love food.';
            echo $head . '<h1>About</h1><p>Write to ' . $text . '</p>' . $foot;
            return;
        }
        if ($path === '/menu') {
            echo $head . '<h1>Menu</h1>' . $foot;
            return;
        }
        http_response_code(404);
        echo $head . 'Not found' . $foot;
    }

    public static function cfEncode(string $email): string
    {
        $key = 0x5a;
        $out = sprintf('%02x', $key);
        foreach (str_split($email) as $ch) {
            $out .= sprintf('%02x', ord($ch) ^ $key);
        }
        return $out;
    }
}
