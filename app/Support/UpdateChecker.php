<?php

declare(strict_types=1);

namespace App\Support;

use App\Console\Commands\CheckForUpdatesCommand;
use App\Data\UpdateStatusData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks whether the running self-hosted instance is behind the latest published
 * stable release, and exposes the outcome to the app.
 *
 * The outbound GitHub check runs on a schedule ({@see CheckForUpdatesCommand})
 * and only the resulting tag is cached; {@see status()} recomputes the comparison
 * live against the running version, so an in-place upgrade clears the indicator
 * without waiting for the next refresh. Every failure is swallowed and leaves the
 * last known-good result in place, so a request never blocks on GitHub.
 */
final class UpdateChecker
{
    /**
     * Cache key holding the latest known stable version (without the leading "v").
     */
    private const string CACHE_KEY = 'updates.latest_version';

    /**
     * The instance's current version standing.
     */
    public function status(): UpdateStatusData
    {
        $current = (string) config('app.version');
        $latest = $this->cachedLatest();
        $updateAvailable = $latest !== null && version_compare($latest, $current, '>');

        return new UpdateStatusData(
            current: $current,
            latest: $latest,
            updateAvailable: $updateAvailable,
            notesUrl: $latest === null ? null : $this->releaseNotesUrl($latest),
        );
    }

    /**
     * Refresh the cached latest release from GitHub.
     *
     * A no-op when the check is disabled (no outbound request is ever made). Any
     * error — network down, air-gapped, rate-limited, GitHub outage, malformed
     * payload — is swallowed, keeping the last known-good cached value.
     */
    public function refresh(): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("https://api.github.com/repos/{$this->repository()}/releases/latest");

            if ($response->failed()) {
                return;
            }

            $latest = $this->normalizeVersion($response->json('tag_name'));

            if ($latest === null) {
                return;
            }

            Cache::put(self::CACHE_KEY, $latest, now()->addHours((int) config('updates.cache_ttl_hours', 12)));
        } catch (Throwable) {
            // Swallow: never block or break the app on a failed update check.
        }
    }

    /**
     * The latest cached stable version, or null when unknown or the check is off.
     */
    private function cachedLatest(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $value = Cache::get(self::CACHE_KEY);

        return is_string($value) ? $value : null;
    }

    /**
     * Normalize a GitHub tag into a bare semver string, or null if it isn't one.
     */
    private function normalizeVersion(mixed $tag): ?string
    {
        if (! is_string($tag)) {
            return null;
        }

        $version = ltrim($tag, 'vV');

        return preg_match('/^\d+\.\d+\.\d+/', $version) === 1 ? $version : null;
    }

    /**
     * The GitHub release-notes URL for a given version.
     */
    private function releaseNotesUrl(string $version): string
    {
        return "https://github.com/{$this->repository()}/releases/tag/v{$version}";
    }

    private function enabled(): bool
    {
        return (bool) config('updates.enabled');
    }

    private function repository(): string
    {
        return (string) config('updates.repository');
    }
}
