<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Branding override directory
    |--------------------------------------------------------------------------
    |
    | Where an operator drops replacement brand assets — the logo mark, the
    | favicons, the apple-touch icon, the Open Graph image and the PWA icon set.
    | Every asset is resolved per request and falls back, individually, to the
    | one shipped in resources/branding, so supplying a logo does not oblige you
    | to supply an Open Graph image.
    |
    | The directory lives outside the image (docker-compose.prod.yml bind-mounts
    | ./branding onto it), so the files survive an upgrade and populating them
    | needs no rebuild. See docs/self-hosting/branding for the filenames.
    |
    */

    'path' => env('BRANDING_PATH', storage_path('branding')),

    /*
    |--------------------------------------------------------------------------
    | "Powered by The Desk" attribution
    |--------------------------------------------------------------------------
    |
    | Whether the attribution line renders in the app footer. On by default.
    | The licence is MIT with no trademark clause, so this is a request rather
    | than a requirement: a documented switch is more honest than a line someone
    | has to patch out. Set BRANDING_ATTRIBUTION=false to hide it.
    |
    */

    'attribution' => (bool) env('BRANDING_ATTRIBUTION', true),

];
