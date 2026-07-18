<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Giphy powers the composer's `/gif` picker. The feature is fully hidden and
    // its endpoints 404 unless `GIPHY_API_KEY` is set (an operator-supplied key
    // from developers.giphy.com), so a default deployment ships it off. `rating`
    // is the strictest content rating Giphy may return — `g` (workplace-safe)
    // by default; loosen to `pg`, `pg-13`, or `r` for a casual community.
    'giphy' => [
        'key' => env('GIPHY_API_KEY'),
        'rating' => env('GIPHY_CONTENT_RATING', 'g'),
    ],

    // Generic OpenID Connect provider (Okta, Microsoft Entra ID, Google
    // Workspace, Auth0, Keycloak, …). Socialite resolves the driver named
    // "oidc" from this block; App\Providers\SsoServiceProvider reads the issuer's
    // discovery document to find the authorize/token/userinfo endpoints. Set
    // `discovery_url` only when the provider does not publish it at the standard
    // `{issuer}/.well-known/openid-configuration` path.
    'oidc' => [
        'client_id' => env('SSO_OIDC_CLIENT_ID'),
        'client_secret' => env('SSO_OIDC_CLIENT_SECRET'),
        // A relative default is resolved by Socialite to an absolute URL against
        // APP_URL (SocialiteManager::formatRedirectUrl), so it matches the
        // callback the IdP is configured with as long as APP_URL is correct.
        'redirect' => env('SSO_OIDC_REDIRECT_URI', '/auth/oidc/callback'),
        'issuer' => env('SSO_OIDC_ISSUER'),
        'discovery_url' => env('SSO_OIDC_DISCOVERY_URL'),
        'scopes' => env('SSO_OIDC_SCOPES'),
    ],

];
