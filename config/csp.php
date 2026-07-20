<?php

declare(strict_types=1);
use Spatie\Csp\Nonce\RandomString;

return [

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    |
    | When enabled (the default), every web response carries the policy built by
    | App\Support\Csp\TheDeskPolicy — the browser-side allow-list that limits the
    | blast radius of any markup injection that gets past output escaping.
    |
    | Set CSP_ENABLED=false only if you intend to serve your own policy from the
    | reverse proxy instead. There is deliberately no "replace the whole policy"
    | env key: an override that could drop the script nonce would silently
    | un-harden the app. Use the additive CSP_EXTRA_* keys below to allow-list
    | extra origins, or turn the app policy off entirely and own it at the proxy.
    |
    */

    'enabled' => (bool) env('CSP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Report-only mode
    |--------------------------------------------------------------------------
    |
    | Sends the policy as Content-Security-Policy-Report-Only instead: the browser
    | logs violations to its console but blocks nothing. Useful for a dry run
    | after adding your own scripts. Defaults to false — a policy that enforces
    | nothing protects nobody — and .env.example turns it on for local
    | development so a policy bug surfaces as a warning rather than a blank page.
    |
    */

    'report_only' => (bool) env('CSP_REPORT_ONLY', false),

    /*
    |--------------------------------------------------------------------------
    | Extra allowed sources
    |--------------------------------------------------------------------------
    |
    | Comma-separated origins appended to the matching directive — for an
    | operator-added analytics snippet, a corporate asset host, or an embedded
    | third-party frame. These are strictly additive: they can never remove the
    | nonce, 'strict-dynamic', or any of our defaults.
    |
    | Note that 'strict-dynamic' makes browsers ignore host allow-lists in
    | script-src, so an extra script host only takes effect for a script tag that
    | is itself loaded by an already-trusted (nonced) script.
    |
    | An external font provider needs both halves: the stylesheet host on
    | CSP_EXTRA_STYLE_SRC and the host its @font-face src: URLs point at on
    | CSP_EXTRA_FONT_SRC. One without the other still fails.
    |
    */

    'extra' => [
        'script-src' => env('CSP_EXTRA_SCRIPT_SRC', ''),
        'style-src' => env('CSP_EXTRA_STYLE_SRC', ''),
        'img-src' => env('CSP_EXTRA_IMG_SRC', ''),
        'connect-src' => env('CSP_EXTRA_CONNECT_SRC', ''),
        'frame-src' => env('CSP_EXTRA_FRAME_SRC', ''),
        'font-src' => env('CSP_EXTRA_FONT_SRC', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Nonce generation (spatie/laravel-csp)
    |--------------------------------------------------------------------------
    |
    | Consumed by the package's service provider, which binds the per-request
    | `csp-nonce`. Nonces are never optional here: script-src carries no
    | 'unsafe-inline', so the app's own inline script only runs because it is
    | nonced. The package's CSP_NONCE_ENABLED env key is deliberately not honoured
    | — switching it off would break every page rather than loosen the policy.
    |
    */

    'nonce_enabled' => true,

    'nonce_generator' => RandomString::class,

];
