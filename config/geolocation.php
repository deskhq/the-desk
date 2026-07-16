<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | IP Geolocation Database
    |--------------------------------------------------------------------------
    |
    | Absolute path to a MaxMind GeoLite2 / GeoIP2 City database (.mmdb) used to
    | derive an approximate city and country for active-session IP addresses at
    | display time. The lookup is fully offline; no third-party API is called.
    |
    | When this file is absent (the default) session locations are silently
    | omitted, so the feature is opt-in: drop a GeoLite2-City.mmdb at this path
    | to enable it. See the self-hosting documentation for how to obtain one.
    |
    */

    'database_path' => env('GEOIP_DATABASE_PATH', storage_path('app/geoip/GeoLite2-City.mmdb')),

];
