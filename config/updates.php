<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Update-available check
    |--------------------------------------------------------------------------
    |
    | When enabled (the default), a scheduled daily command asks GitHub for the
    | latest published stable release and caches the result, so the app can
    | surface a low-key "update available" indicator to self-hosters running an
    | outdated version. Set UPDATE_CHECK_ENABLED=false for a fully air-gapped
    | instance: no outbound version check is ever made.
    |
    */

    'enabled' => (bool) env('UPDATE_CHECK_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Source repository
    |--------------------------------------------------------------------------
    |
    | The GitHub "owner/repo" the check queries for releases and links to for
    | release notes. Forks can point this at their own upstream.
    |
    */

    'repository' => env('UPDATE_CHECK_REPOSITORY', 'deskhq/the-desk'),

    /*
    |--------------------------------------------------------------------------
    | Cache lifetime (hours)
    |--------------------------------------------------------------------------
    |
    | How long a successful check is trusted before the next daily refresh. The
    | last known-good result is kept on any failure, so this is a soft ceiling.
    |
    */

    'cache_ttl_hours' => (int) env('UPDATE_CHECK_CACHE_TTL_HOURS', 12),

];
