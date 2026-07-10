/**
 * Timestamp formatting helpers. All rendering happens client-side in a target
 * IANA time zone — the viewer's stored zone where known, otherwise the runtime's
 * local zone (passing `timeZone: undefined` to the Intl APIs falls back to it).
 */

/**
 * Format an ISO timestamp as a time of day (e.g. "3:45 PM").
 */
export function formatTimeOfDay(iso: string, timeZone?: string): string {
    return new Date(iso).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
        timeZone,
    });
}

/**
 * Format an ISO timestamp as an abbreviated date and time (e.g. "Jul 10, 3:45 PM").
 */
export function formatDateTime(iso: string, timeZone?: string): string {
    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone,
    });
}

/**
 * A person's current wall-clock time (e.g. "3:45 PM") in their time zone, or
 * null when the zone is unknown or not a valid IANA identifier.
 */
export function formatLocalTime(
    timeZone: string | null,
    at: Date,
): string | null {
    if (!timeZone) {
        return null;
    }

    try {
        return at.toLocaleTimeString(undefined, {
            hour: 'numeric',
            minute: '2-digit',
            timeZone,
        });
    } catch {
        return null;
    }
}
