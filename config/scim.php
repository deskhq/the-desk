<?php

declare(strict_types=1);

// Configuration for arietimmerman/laravel-scim-server. The SCIM routes are NOT
// auto-published here: App\Providers\SsoServiceProvider mounts them only when
// SCIM is enabled (config('sso.scim.enabled')) and wraps them in the bearer-token
// guard, so the base path, middleware, and on/off switch all live in config/sso.php.
return [
    'publish_routes' => false,

    // Hoist the core User attributes (userName, name, active, …) to the top of
    // the resource instead of nesting them under the schema urn, which is what
    // standard SCIM clients (Okta, Entra ID, …) expect.
    'omit_main_schema_in_return' => true,
    'omit_null_values' => env('SCIM_OMIT_NULL_VALUES', true),

    'path' => env('SCIM_BASE_PATH', '/scim'),
    'domain' => env('SCIM_DOMAIN'),
    'middleware' => env('SCIM_MIDDLEWARE', []),
    'public_middleware' => env('SCIM_PUBLIC_MIDDLEWARE', []),

    'pagination' => [
        'defaultPageSize' => 10,
        'maxPageSize' => 100,
        'cursorPaginationEnabled' => true,
    ],

    'authenticationSchemes' => [
        'oauthbearertoken',
    ],
];
