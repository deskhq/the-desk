<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Gravatar Avatars
    |--------------------------------------------------------------------------
    |
    | User avatars are derived from Gravatar (https://gravatar.com) using the
    | MD5 hash of the user's email address, so the raw address is never placed
    | in the URL. Set GRAVATAR_ENABLED=false to stop deriving avatars entirely
    | (no outbound requests to gravatar.com) — every user then falls back to the
    | initials avatar.
    |
    */

    'enabled' => (bool) env('GRAVATAR_ENABLED', true),

    /*
    | The Gravatar avatar endpoint. Point this at a self-hosted or mirrored
    | instance if you don't want to depend on gravatar.com.
    */
    'base_url' => env('GRAVATAR_URL', 'https://www.gravatar.com/avatar'),

    /*
    | The requested image size in pixels (Gravatar serves a square image).
    */
    'size' => (int) env('GRAVATAR_SIZE', 200),

    /*
    | The `d=` fallback passed to Gravatar. The default `404` makes Gravatar
    | return a 404 for users without an account so the frontend falls back to
    | its own initials avatar rather than Gravatar's generic silhouette. Other
    | valid values include `mp` (mystery person), `identicon`, or a URL.
    */
    'default' => env('GRAVATAR_DEFAULT', '404'),

];
