<?php

declare(strict_types=1);

// Whether an OIDC provider is wired up: both the issuer (for discovery) and a
// client id are required. Computed once here so it can gate both the login-page
// entry point and — crucially — the SSO-only enforcement below.
$oidcConfigured = filled(env('SSO_OIDC_CLIENT_ID')) && filled(env('SSO_OIDC_ISSUER'));

return [

    /*
    |--------------------------------------------------------------------------
    | SSO-only enforcement
    |--------------------------------------------------------------------------
    |
    | Directory login (OIDC now, LDAP later) sits alongside Fortify's password
    | login by default, so a break-glass password account survives an IdP
    | outage. Set AUTH_SSO_ONLY=true to funnel all access through the directory:
    | Fortify registration and password login are disabled, leaving SSO as the
    | only way in.
    |
    | `enforced` is the *effective* switch used across the app. Enforcement only
    | takes hold when a provider is actually configured — otherwise AUTH_SSO_ONLY
    | with no usable SSO would disable every sign-in path and lock everyone out.
    |
    */

    'sso_only' => (bool) env('AUTH_SSO_ONLY', false),

    'enforced' => (bool) env('AUTH_SSO_ONLY', false) && $oidcConfigured,

    /*
    |--------------------------------------------------------------------------
    | Default team for provisioned users
    |--------------------------------------------------------------------------
    |
    | The team a just-in-time provisioned directory user is added to as a Member.
    | Leave blank to use the sole team when the instance has exactly one; when it
    | resolves to nothing the account falls back to its own personal team.
    |
    */

    'default_team_id' => env('SSO_DEFAULT_TEAM_ID'),

    /*
    |--------------------------------------------------------------------------
    | OpenID Connect
    |--------------------------------------------------------------------------
    |
    | Whether OIDC login is wired up. Drives the "Sign in with SSO" entry point
    | on the login page (shown only when a provider is configured). The provider
    | credentials themselves live in the `oidc` block of config/services.php.
    |
    */

    'oidc' => [
        'enabled' => $oidcConfigured,
    ],

];
