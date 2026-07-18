<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

// SSO-only enforcement only bites when a provider is actually configured (see
// config/sso.php), so a stray AUTH_SSO_ONLY can never disable both directory and
// password sign-in and lock everyone out. A configured provider is either OIDC
// (issuer + client id) or an LDAP directory (host + base DN); this mirrors the
// `sso.enforced` computation so the registration gate below stays in lockstep.
$oidcConfigured = filled(env('SSO_OIDC_CLIENT_ID')) && filled(env('SSO_OIDC_ISSUER'));
$ldapConfigured = filled(env('LDAP_HOST')) && filled(env('LDAP_BASE_DN'));
$ssoEnforced = env('AUTH_SSO_ONLY', false) && ($oidcConfigured || $ldapConfigured);

return [

    /*
    |--------------------------------------------------------------------------
    | Fortify Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Fortify will use while
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | Fortify Password Broker
    |--------------------------------------------------------------------------
    |
    | Here you may specify which password broker Fortify can use when a user
    | is resetting their password. This configured value should match one
    | of your password brokers setup in your "auth" configuration file.
    |
    */

    'passwords' => 'users',

    /*
    |--------------------------------------------------------------------------
    | Username / Email
    |--------------------------------------------------------------------------
    |
    | This value defines which model attribute should be considered as your
    | application's "username" field. Typically, this might be the email
    | address of the users but you are free to change this value here.
    |
    | Out of the box, Fortify expects forgot password and reset password
    | requests to have a field named 'email'. If the application uses
    | another name for the field you may define it below as needed.
    |
    */

    'username' => 'email',

    'email' => 'email',

    /*
    |--------------------------------------------------------------------------
    | Lowercase Usernames
    |--------------------------------------------------------------------------
    |
    | This value defines whether usernames should be lowercased before saving
    | them in the database, as some database system string fields are case
    | sensitive. You may disable this for your application if necessary.
    |
    */

    'lowercase_usernames' => true,

    /*
    |--------------------------------------------------------------------------
    | Home Path
    |--------------------------------------------------------------------------
    |
    | Here you may configure the path where users will get redirected during
    | authentication or password reset when the operations are successful
    | and the user is authenticated. You are free to change this value.
    |
    */

    'home' => '/',

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Prefix / Subdomain
    |--------------------------------------------------------------------------
    |
    | Here you may specify which prefix Fortify will assign to all the routes
    | that it registers with the application. If necessary, you may change
    | subdomain under which all of the Fortify routes will be available.
    |
    */

    'prefix' => '',

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Fortify Routes Middleware
    |--------------------------------------------------------------------------
    |
    | Here you may specify which middleware Fortify will assign to the routes
    | that it registers with the application. If necessary, you may change
    | these middleware but typically this provided default is preferred.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | By default, Fortify will throttle logins to five requests per minute for
    | every email and IP address combination. However, if you would like to
    | specify a custom rate limiter to call then you may specify it here.
    |
    */

    'limiters' => [
        'login' => 'login',
    ],

    /*
    |--------------------------------------------------------------------------
    | Register View Routes
    |--------------------------------------------------------------------------
    |
    | Here you may specify if the routes returning views should be disabled as
    | you may not need them when building your own application. This may be
    | especially true if you're writing a custom single-page application.
    |
    */

    'views' => true,

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Some of the Fortify features are optional. You may disable the features
    | by removing them from this array. You're free to only remove some of
    | these features, or you can even remove all of these if you need to.
    |
    */

    'features' => array_filter([
        // Self-service registration is on by default, but disabled whenever the
        // operator closes public sign-ups (REGISTRATION_ENABLED=false), routes
        // all access through a configured directory (AUTH_SSO_ONLY=true), or runs
        // the public demo (DEMO_MODE=true) — where a fresh account would carry its
        // own unguarded personal team and sidestep the demo guard rails.
        (env('REGISTRATION_ENABLED', true) && ! $ssoEnforced && ! env('DEMO_MODE', false)) ? Features::registration() : null,
        Features::resetPasswords(),
        Features::emailVerification(),
        // Two-factor authentication is registered unconditionally so the Fortify
        // 2FA routes always exist and Wayfinder keeps emitting their route
        // modules — a feature that defaults *off* would otherwise drop those
        // routes and break the frontend build (unlike REGISTRATION_ENABLED, which
        // gets away with conditional registration only because it defaults on).
        // Availability is instead gated at runtime by `two_factor_enabled` below,
        // mirroring how `email_verification_enabled` toggles behaviour without
        // touching route registration. `confirm` requires a TOTP code to activate
        // enrolment; `confirmPassword` requires a fresh password confirmation
        // before any 2FA change.
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
        // Passkeys (WebAuthn) are registered unconditionally for the same reason
        // as two-factor above: a feature that defaults *off* would drop its
        // Fortify passkey routes and stop Wayfinder emitting their route modules,
        // breaking the frontend build. Availability is gated at runtime by
        // `passkeys_enabled` below (mirroring `two_factor_enabled`); only whether
        // the Security page and login screen surface passkeys is toggled, never
        // route registration. `confirmPassword` requires a fresh password
        // confirmation before registering or removing a passkey.
        Features::passkeys([
            'confirmPassword' => true,
        ]),
    ]),

    /*
    |--------------------------------------------------------------------------
    | Email Verification Enforcement
    |--------------------------------------------------------------------------
    |
    | A single deploy-time flag letting self-hosters require new accounts to
    | confirm their email before using the app. It defaults to off, preserving
    | today's behaviour for the hosted demo and existing deployments. The verify
    | routes stay registered either way (so the reused verify screen keeps
    | working); only the requirement is toggled — App\Models\User::hasVerifiedEmail()
    | reads this flag and treats every account as verified when it is off, so the
    | `verified` middleware and the send-on-register listener become no-ops.
    |
    */

    'email_verification_enabled' => env('EMAIL_VERIFICATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication Availability
    |--------------------------------------------------------------------------
    |
    | A deploy-time flag letting self-hosters offer TOTP two-factor sign-in.
    | It defaults to off, preserving today's behaviour for the hosted demo and
    | existing deployments. The Fortify 2FA feature (and its routes) stay
    | registered either way so the frontend build is stable; only whether the
    | Security settings page surfaces 2FA management is toggled — the
    | SecurityController reads this flag and hides the 2FA affordances when it is
    | off. Under SSO enforcement the identity provider owns MFA, so the app-native
    | option hides regardless of this flag.
    |
    */

    'two_factor_enabled' => env('TWO_FACTOR_AUTH_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Passkey (WebAuthn) Availability
    |--------------------------------------------------------------------------
    |
    | A deploy-time flag letting self-hosters offer passwordless passkey sign-in
    | (WebAuthn — Touch ID, Face ID, Windows Hello, security keys). It defaults to
    | off, preserving today's behaviour for the hosted demo and existing
    | deployments. The Fortify passkey feature (and its routes) stay registered
    | either way so the frontend build is stable; only whether the Security
    | settings page and the login screen surface passkeys is toggled — the
    | SecurityController and the login view read this flag and hide the passkey
    | affordances when it is off. Under SSO enforcement the identity provider owns
    | authentication, so the app-native option hides regardless of this flag.
    |
    */

    'passkeys_enabled' => env('PASSKEYS_ENABLED', false),

];
