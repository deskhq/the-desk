import type { SearchParams } from '@/lib/searchTokens';

/**
 * The Search destination's URL model.
 *
 * #391's rule that "the URL is the state" is what makes a search shareable, and
 * in the dock panel it is also what makes the panel reload-proof: the criteria
 * live on the workspace route beside `?nav=search`, never in component state the
 * server cannot see. So the panel reads its query and facets back out of the URL
 * rather than out of a prop — a cold load, a history traversal and a hand-off
 * from the ⌘K palette all arrive the same way.
 *
 * The normalization below deliberately mirrors `App\Support\MessageSearchPanel`:
 * both sides read the same URL, and the panel decides whether to refetch by
 * comparing what it reads against the criteria the server says it answered. Let
 * the two rules drift and the panel would keep asking for a URL the server keeps
 * resolving differently, so they are covered by tests on both sides.
 */

/** The shared props the panel pulls when its criteria change. */
export const SEARCH_PANEL_PROPS = ['searchCriteria', 'searchResults'] as const;

/** The prop carrying the cross-workspace channel union, fetched once per open. */
export const SEARCH_WORKSPACE_CHANNELS_PROP = 'searchWorkspaceChannels';

/** The longest value taken from the URL, mirroring the server's own cap. */
const MAX_VALUE_LENGTH = 255;

/** The length of a `YYYY-MM-DD` day, for reading one back off an ISO string. */
const ISO_DAY_LENGTH = 10;

/**
 * Inertia's `page.url` is a root-relative path, which `URL` cannot parse on its
 * own. The base below only satisfies that constructor — it never reaches the
 * result, which is rebuilt from the path, query and hash alone.
 */
const URL_BASE = 'http://localhost';

/** The query params the search criteria are pinned onto the URL as. */
const SEARCH_URL_PARAMS = [
    'q',
    'from',
    'in',
    'after',
    'before',
    'scope',
] as const;

const ISO_DAY = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Whether a value names a real calendar day, not merely something shaped like
 * one: `2026-13-45` and a February 29th outside a leap year are rejected, which
 * is what the server's own `Carbon::hasFormat` check does. Were the two to differ
 * the panel would ask for a facet the server keeps throwing away.
 */
function isCalendarDay(value: string): boolean {
    if (!ISO_DAY.test(value)) {
        return false;
    }

    const parsed = new Date(`${value}T00:00:00Z`);

    return (
        !Number.isNaN(parsed.getTime()) &&
        parsed.toISOString().slice(0, ISO_DAY_LENGTH) === value
    );
}

/**
 * Read the search criteria a URL carries, normalized the way the server
 * normalizes them: an over-long value, a date facet that is not a plain calendar
 * day and a scope that names nothing are all dropped rather than rendered as a
 * chip nobody can explain.
 */
export function searchParamsFromUrl(url: string): SearchParams {
    const params = new URL(url, URL_BASE).searchParams;

    const value = (key: 'q' | 'from' | 'in'): string | undefined => {
        const raw = (params.get(key) ?? '').trim();

        return raw === '' || raw.length > MAX_VALUE_LENGTH ? undefined : raw;
    };

    const day = (key: 'after' | 'before'): string | undefined => {
        const raw = params.get(key);

        return raw !== null && isCalendarDay(raw) ? raw : undefined;
    };

    return {
        q: value('q'),
        from: value('from'),
        in: value('in'),
        after: day('after'),
        before: day('before'),
        scope: params.get('scope') === 'all' ? 'all' : undefined,
    };
}

/**
 * Pin a set of criteria onto a URL, leaving every other query param in place
 * (`?nav=search` itself, a `?thread=` panel behind it). An absent facet drops
 * its param rather than spelling out an empty one, so a cleared search leaves
 * the address bar as tidy as it found it.
 */
export function urlForSearchParams(url: string, params: SearchParams): string {
    const target = new URL(url, URL_BASE);

    for (const key of SEARCH_URL_PARAMS) {
        const value = params[key];

        if (value === undefined || value === '') {
            target.searchParams.delete(key);
        } else {
            target.searchParams.set(key, value);
        }
    }

    return `${target.pathname}${target.search}${target.hash}`;
}

/**
 * A comparable digest of one set of criteria, tolerant of the two shapes they
 * arrive in: the client's own (absent facets `undefined`, scope implied) and the
 * server's echo (absent facets `null`, scope always spelled out).
 */
export function searchFingerprint(
    params: Partial<Record<(typeof SEARCH_URL_PARAMS)[number], string | null>>,
): string {
    const scope = params.scope === 'all' ? 'all' : 'team';

    return JSON.stringify(
        SEARCH_URL_PARAMS.map((key) =>
            key === 'scope' ? scope : (params[key] ?? ''),
        ),
    );
}
