<?php

namespace App\Support;

use App\Concerns\GeneratesUniqueTeamSlugs;
use Illuminate\Support\Str;

/**
 * Derives a usable slug from a display name.
 *
 * Str::slug() transliterates what it can (Cyrillic, Greek and Arabic all
 * survive) but strips every character it has no Latin equivalent for, so a name
 * written entirely in e.g. Japanese, Korean or Hebrew — or in punctuation or
 * emoji — comes back empty. Slugs are route keys here, so an empty one makes
 * the record unreachable with no UI path back to it (issues #921 and #924).
 */
final class NameSlug
{
    /**
     * The number of fingerprint characters appended to a fallback base.
     *
     * Eight hex characters keep a collision between two different names far
     * rarer than the name clash the caller already has to handle anyway, while
     * staying short enough to read in a URL.
     */
    private const int FINGERPRINT_LENGTH = 8;

    /**
     * Slug a name, falling back to the given base when nothing survives.
     *
     * The base is shared by every unsluggable name, so this suits a caller that
     * resolves collisions itself — {@see GeneratesUniqueTeamSlugs}
     * keeps team slugs unique with a numeric suffix.
     */
    public static function make(string $name, string $fallback): string
    {
        $slug = Str::slug($name);

        return $slug === '' ? $fallback : $slug;
    }

    /**
     * Slug a name, falling back to a base that stays specific to that name.
     *
     * Unlike {@see make()}, two different unsluggable names get two different
     * slugs, so a caller whose slugs are unique per team does not have to probe
     * the database to avoid a clash. The fallback is derived from the name
     * alone, which lets a validation pre-check compute exactly the slug the
     * write path will store — the guarantee that keeps a non-Latin channel name
     * from being rejected as already taken (issue #924).
     */
    public static function distinct(string $name, string $fallback): string
    {
        return self::make($name, $fallback.'-'.self::fingerprint($name));
    }

    /**
     * A short, stable, slug-safe digest of a name.
     */
    private static function fingerprint(string $name): string
    {
        return substr(hash('xxh128', $name), 0, self::FINGERPRINT_LENGTH);
    }
}
