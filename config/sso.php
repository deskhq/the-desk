<?php

declare(strict_types=1);

// Whether an OIDC provider is wired up: both the issuer (for discovery) and a
// client id are required. Computed once here so it can gate both the login-page
// entry point and — crucially — the SSO-only enforcement below.
$oidcConfigured = filled(env('SSO_OIDC_CLIENT_ID')) && filled(env('SSO_OIDC_ISSUER'));

// Whether a directory (LDAP/AD) is wired up: a host to reach and a base DN to
// search under are the minimum. Like OIDC above it gates both the directory
// login path and SSO-only enforcement.
$ldapConfigured = filled(env('LDAP_HOST')) && filled(env('LDAP_BASE_DN'));

// Whether SCIM provisioning is wired up: the endpoint only functions with a
// bearer token to authenticate the identity provider, so its presence is what
// mounts (and guards) the SCIM routes.
$scimConfigured = filled(env('SCIM_TOKEN'));

return [

    /*
    |--------------------------------------------------------------------------
    | SSO-only enforcement
    |--------------------------------------------------------------------------
    |
    | Directory login (OIDC or LDAP/AD) sits alongside Fortify's password login
    | by default, so a break-glass password account survives an IdP outage. Set
    | AUTH_SSO_ONLY=true to funnel all access through the directory: Fortify
    | registration is disabled and the local-password path is blocked, leaving
    | SSO as the only way in.
    |
    | `enforced` is the *effective* switch used across the app. Enforcement only
    | takes hold when a provider (OIDC or LDAP) is actually configured —
    | otherwise AUTH_SSO_ONLY with no usable SSO would disable every sign-in path
    | and lock everyone out.
    |
    */

    'sso_only' => (bool) env('AUTH_SSO_ONLY', false),

    'enforced' => (bool) env('AUTH_SSO_ONLY', false) && ($oidcConfigured || $ldapConfigured),

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

    /*
    |--------------------------------------------------------------------------
    | LDAP / Active Directory
    |--------------------------------------------------------------------------
    |
    | Directory bind authentication. When enabled, the app login form binds the
    | submitted credentials against the directory (the connection itself lives in
    | config/ldap.php). On a successful bind the entry is matched to an app user
    | by its mail attribute, keyed by its stable GUID, and JIT-provisioned into
    | the default team as a Member — the same identity rules OIDC follows.
    |
    | `attributes` maps directory attributes to app fields: `username` is the
    | attribute matched against the login form value (default the mail attribute,
    | so users sign in with their email; set it to e.g. `samaccountname` to sign
    | in with a directory username), `mail` becomes the app email, `name` the
    | display name synced on every login, and `guid` the stable identity.
    |
    */

    'ldap' => [
        'enabled' => $ldapConfigured,
        'connection' => env('LDAP_CONNECTION', 'default'),
        'attributes' => [
            'username' => env('LDAP_ATTR_USERNAME', 'mail'),
            'mail' => env('LDAP_ATTR_MAIL', 'mail'),
            'name' => env('LDAP_ATTR_NAME', 'cn'),
            'guid' => env('LDAP_ATTR_GUID', 'objectguid'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SCIM 2.0 provisioning
    |--------------------------------------------------------------------------
    |
    | A bearer-token REST API the corporate IdP (Okta, Entra ID, OneLogin, …)
    | pushes user create / update / deactivate events to, so directory removals
    | become automatic account deactivation here. Unlike OIDC/LDAP it is not a
    | login path: the IdP authenticates with `SCIM_TOKEN` and every request is
    | rejected without it (App\Http\Middleware\EnsureScimBearerToken).
    |
    | `enabled` mounts the endpoints only when a token is set — an unauthenticated
    | provisioning API is never exposed by accident. `path` is the route *prefix*
    | (default `/scim`); the package mounts the versioned resources beneath it, so
    | with the default the IdP's base URL is `${APP_URL}/scim/v2` and users live at
    | `/scim/v2/Users`.
    |
    */

    'scim' => [
        'enabled' => $scimConfigured,
        'token' => env('SCIM_TOKEN'),
        'path' => env('SCIM_BASE_PATH', '/scim'),
    ],

];
