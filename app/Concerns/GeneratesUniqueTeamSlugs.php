<?php

namespace App\Concerns;

use App\Support\NameSlug;

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
     * A name with no sluggable characters would otherwise leave the team with
     * an empty slug and so unreachable (issue #921); it gets the generic base
     * instead, and the suffix machinery above keeps that unique.
     */
    private static function sluggifyTeamName(string $name): string
    {
        return NameSlug::make($name, self::FALLBACK_SLUG);
    }
}
