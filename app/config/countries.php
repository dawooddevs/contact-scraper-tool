<?php

/*
 * Countries available in the campaign wizard.
 *   sources   – source keys from app/config/sources.php (first = default)
 *   locations – suggestions for the location field (free text is always allowed)
 */

return [
    'AU' => [
        'name' => 'Australia',
        'sources' => ['yellowpages_au'],
        'locations' => [
            'Sydney NSW', 'Melbourne VIC', 'Brisbane QLD', 'Perth WA', 'Adelaide SA', 'Gold Coast QLD',
            'Canberra ACT', 'Newcastle NSW', 'Sunshine Coast QLD', 'Wollongong NSW', 'Hobart TAS', 'Geelong VIC',
            'Townsville QLD', 'Cairns QLD', 'Darwin NT', 'Toowoomba QLD', 'Ballarat VIC', 'Bendigo VIC',
            'Launceston TAS', 'Mackay QLD', 'Rockhampton QLD', 'Bundaberg QLD', 'Hervey Bay QLD', 'Ipswich QLD',
            'Logan QLD', 'Parramatta NSW', 'Penrith NSW', 'Central Coast NSW', 'Coffs Harbour NSW', 'Port Macquarie NSW',
            'Wagga Wagga NSW', 'Albury NSW', 'Tamworth NSW', 'Orange NSW', 'Dubbo NSW', 'Bunbury WA', 'Mandurah WA',
            'Geraldton WA', 'Mildura VIC', 'Shepparton VIC', 'Frankston VIC', 'Fortitude Valley QLD', 'Surfers Paradise QLD',
            'Southport QLD', 'Chermside QLD', 'Redcliffe QLD', 'Caboolture QLD', 'North Sydney NSW', 'Bondi NSW',
            'St Kilda VIC', 'Fremantle WA', 'Glenelg SA',
        ],
    ],
    'US' => [
        'name' => 'United States',
        'sources' => ['yelp'],
        'locations' => [
            'New York, NY', 'Los Angeles, CA', 'Chicago, IL', 'Houston, TX', 'Phoenix, AZ', 'Philadelphia, PA',
            'San Antonio, TX', 'San Diego, CA', 'Dallas, TX', 'Austin, TX', 'San Jose, CA', 'Jacksonville, FL',
            'Fort Worth, TX', 'Columbus, OH', 'Charlotte, NC', 'Indianapolis, IN', 'San Francisco, CA', 'Seattle, WA',
            'Denver, CO', 'Washington, DC', 'Boston, MA', 'Nashville, TN', 'Las Vegas, NV', 'Portland, OR',
            'Miami, FL', 'Atlanta, GA', 'Orlando, FL', 'Tampa, FL', 'Minneapolis, MN', 'Detroit, MI',
            'Oklahoma City, OK', 'Louisville, KY', 'Baltimore, MD', 'Milwaukee, WI', 'Albuquerque, NM', 'Tucson, AZ',
            'Sacramento, CA', 'Kansas City, MO', 'Raleigh, NC', 'Salt Lake City, UT', 'New Orleans, LA', 'Pittsburgh, PA',
            'Cincinnati, OH', 'St. Louis, MO', 'Cleveland, OH', 'Honolulu, HI', 'Brooklyn, NY', 'Scottsdale, AZ',
        ],
    ],
];
