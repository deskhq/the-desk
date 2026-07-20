<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | Tells the browser to reach this host over HTTPS only, for the configured
    | number of seconds. Without it the very first (or a typed) navigation still
    | goes out as plain HTTP, which is the window an on-path attacker uses to
    | SSL-strip the connection and read the session cookie before the redirect
    | to HTTPS ever happens.
    |
    | The header is only ever sent on a request that arrived over HTTPS (the
    | reverse proxy's X-Forwarded-Proto is trusted for exactly this), so a
    | plain-HTTP LAN deployment can never lock itself out of its own hostname.
    |
    | Set HSTS_ENABLED=false only if you send the header from your reverse proxy
    | instead — two sources would fight over the max-age.
    |
    */

    'hsts' => [

        'enabled' => (bool) env('HSTS_ENABLED', true),

        /*
        | Seconds the browser should remember the pin. One year is the value the
        | preload list requires and what every hardening guide asks for. Lower it
        | while rolling HSTS out if you want a short escape hatch; 0 tells
        | browsers to forget the host again.
        */
        'max_age' => (int) env('HSTS_MAX_AGE', 31536000),

        /*
        | Extends the pin to every subdomain. Turn it off only if a subdomain of
        | your app's host must stay reachable over plain HTTP.
        */
        'include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', true),

        /*
        | Opts the domain into the browsers' built-in preload list, so even a
        | first-ever visit never touches HTTP. Off by default and deliberately
        | so: submission is effectively irreversible on a human timescale, and it
        | commits every subdomain of the registrable domain, not just this app.
        | Only turn it on if you own the whole domain and intend to submit it at
        | https://hstspreload.org.
        */
        'preload' => (bool) env('HSTS_PRELOAD', false),

    ],

];
