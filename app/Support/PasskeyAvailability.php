<?php

declare(strict_types=1);

namespace App\Support;

class PasskeyAvailability
{
    /**
     * Whether app-native passkeys are on offer on this instance.
     *
     * The single source of truth for the condition, because it is read from four
     * places that must agree: the route guard that 404s the Fortify endpoints,
     * the Security page that surfaces management, the login screen's passwordless
     * entry point, and the post-registration enrolment prompt. Under enforced SSO
     * the identity provider owns authentication, so the app-native option is
     * withdrawn regardless of the toggle.
     */
    public static function enabled(): bool
    {
        return (bool) config('fortify.passkeys_enabled') && ! config('sso.enforced');
    }
}
