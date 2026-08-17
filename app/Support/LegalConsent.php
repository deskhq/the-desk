<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The operator's terms and privacy notice, and whether registration must ask
 * about them.
 *
 * Both links have to be configured for the consent row to exist at all — the
 * register screen and the validator read that single verdict from here, so the
 * checkbox and the rule enforcing it can never disagree.
 */
final class LegalConsent
{
    /**
     * The terms-of-service URL, or null when the pair is not fully configured.
     */
    public static function termsUrl(): ?string
    {
        return self::isRequired() ? self::url('legal.terms_url') : null;
    }

    /**
     * The privacy-notice URL, or null when the pair is not fully configured.
     */
    public static function privacyUrl(): ?string
    {
        return self::isRequired() ? self::url('legal.privacy_url') : null;
    }

    /**
     * Whether a registration must carry an explicit agreement.
     */
    public static function isRequired(): bool
    {
        return self::url('legal.terms_url') !== null && self::url('legal.privacy_url') !== null;
    }

    /**
     * Read a configured URL, treating a blank value as absent.
     */
    private static function url(string $key): ?string
    {
        $value = config($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
