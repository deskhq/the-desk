import { card } from '@/routes/teams/members';
import type { UserProfile } from '@/types';

// Resolved profiles, memoised per (team, user) so repeated hovers over the same
// name never refetch. Shared across every hover card for the app's lifetime.
const cache = new Map<string, UserProfile>();

// In-flight requests, so two hover cards for the same person opened at once
// share a single round-trip.
const inFlight = new Map<string, Promise<UserProfile | null>>();

function cacheKey(teamSlug: string, userId: string): string {
    return `${teamSlug}:${userId}`;
}

/**
 * Fetch a member's profile for the hover card. Returns null when it can't be
 * loaded (e.g. the user has left the team), letting the card fall back to just
 * the name it already has.
 */
export function fetchUserProfile(
    teamSlug: string,
    userId: string,
): Promise<UserProfile | null> {
    const key = cacheKey(teamSlug, userId);

    const cached = cache.get(key);

    if (cached) {
        return Promise.resolve(cached);
    }

    const pending = inFlight.get(key);

    if (pending) {
        return pending;
    }

    const request = fetch(card([teamSlug, userId]).url, {
        headers: { Accept: 'application/json' },
    })
        .then((response) =>
            response.ok ? (response.json() as Promise<UserProfile>) : null,
        )
        .then((profile) => {
            if (profile) {
                cache.set(key, profile);
            }

            return profile;
        })
        .catch(() => null)
        .finally(() => {
            inFlight.delete(key);
        });

    inFlight.set(key, request);

    return request;
}
