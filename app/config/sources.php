<?php

/*
 * Registered lead sources: key => class.
 * To add a website, create a class in app/Sources (see YellowPagesAu.php for
 * a complete example), register it here and list its key under a country in
 * app/config/countries.php.
 */

return [
    'yellowpages_au' => App\Sources\YellowPagesAu::class,
    'yelp' => App\Sources\Yelp::class,
];
