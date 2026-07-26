<?php

namespace App\Concerns;

use Illuminate\Support\Str;

trait GeneratesUniqueTeamSlugs
{
    /**
     * Slug used as the base when a name carries no sluggable characters.
     */
    private const string FALLBACK_SLUG = 'team';

    /**
     * Generate a unique slug for the team.
     */
    protected static function generateUniqueTeamSlug(string $name, ?string $excludeId = null): string
    {
        $defaultSlug = self::sluggifyTeamName($name);

        $query = static::withTrashed()
            ->where(function ($query) use ($defaultSlug): void {
                $query->where('slug', $defaultSlug)
                    ->orWhere('slug', 'like', $defaultSlug.'-%');
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $existingSlugs = $query->pluck('slug');

        $maxSuffix = $existingSlugs
            ->map(function (string $slug) use ($defaultSlug): ?int {
                if ($slug === $defaultSlug) {
                    return 0;
                }
                if (preg_match('/^'.preg_quote($defaultSlug, '/').'-(\d+)$/', $slug, $matches)) {
                    return (int) $matches[1];
                }

                return null;
            })
            ->filter(fn (?int $suffix): bool => $suffix !== null)
            ->max() ?? 0;

        return $existingSlugs->isEmpty()
            ? $defaultSlug
            : $defaultSlug.'-'.($maxSuffix + 1);
    }

    /**
     * Slug a team name, falling back when the name slugs to nothing.
     *
     * Str::slug() transliterates what it can (Cyrillic, Greek and Arabic all
     * survive) but strips every character it has no Latin equivalent for, so a
     * name written entirely in e.g. Japanese, Korean or Hebrew — or in
     * punctuation or emoji — comes back empty. An empty slug is unusable as a
     * route key and would make the team unreachable (issue #921), so such a
     * name gets the generic base instead and the caller's suffix machinery
     * keeps it unique.
     */
    private static function sluggifyTeamName(string $name): string
    {
        $slug = Str::slug($name);

        return $slug === '' ? self::FALLBACK_SLUG : $slug;
    }
}
