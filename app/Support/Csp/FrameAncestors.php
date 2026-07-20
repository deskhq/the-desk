<?php

declare(strict_types=1);

namespace App\Support\Csp;

use Spatie\Csp\Keyword;

/**
 * The operator's answer to "who may embed this app in a frame?", read once from
 * `csp.frame_ancestors` and expressed two ways: as the CSP frame-ancestors
 * sources, and as the legacy X-Frame-Options header browsers that predate CSP3
 * read instead.
 */
final class FrameAncestors
{
    /**
     * Sources for the frame-ancestors directive. Keywords come back as Keyword
     * cases so the policy quotes them; origins are passed through verbatim.
     *
     * @return list<Keyword|string>
     */
    public static function sources(): array
    {
        $sources = array_values(array_filter(array_map(
            self::normalise(...),
            array_map(trim(...), explode(',', (string) config('csp.frame_ancestors', ''))),
        )));

        // Fail closed twice over: a blank key must not leave the app framable by
        // anyone, and `none` written beside an origin is a contradiction whose
        // safe reading is "deny" — a blocked embed is a visible bug, an
        // accidentally framable app is not.
        if ($sources === [] || in_array(Keyword::NONE, $sources, true)) {
            return [Keyword::NONE];
        }

        return $sources;
    }

    /**
     * The equivalent X-Frame-Options value, or null when the policy cannot be
     * expressed in that header at all — it has no allow-list form, so an
     * operator who named origins gets frame-ancestors only.
     */
    public static function frameOptions(): ?string
    {
        return match (self::sources()) {
            [Keyword::NONE] => 'DENY',
            [Keyword::SELF] => 'SAMEORIGIN',
            default => null,
        };
    }

    /**
     * Map the keywords onto their enum cases, accepting the spellings an
     * operator plausibly reaches for (`'self'` copied out of a policy, `NONE`
     * shouted into an env file).
     */
    private static function normalise(string $source): Keyword|string
    {
        return match (mb_strtolower(trim($source, "'"))) {
            'none' => Keyword::NONE,
            'self' => Keyword::SELF,
            default => $source,
        };
    }
}
