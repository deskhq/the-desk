/**
 * Which followed threads the Threads panel lists. Mirrors the backend enum, which
 * owns the filtering: the inbox is cursor-paginated, so the pill has to travel to
 * the server rather than sieve a loaded page.
 */
export type ThreadInboxFilter = App.Enums.ThreadInboxFilter;

/** The pills, in the order the panel lays them out. */
export const THREAD_INBOX_FILTERS: readonly ThreadInboxFilter[] = [
    'unread',
    'all',
];

/** The filter the panel opens on: what needs the viewer's attention. */
export const DEFAULT_THREAD_INBOX_FILTER: ThreadInboxFilter = 'unread';

/** The query parameter carrying the active filter. */
const FILTER_PARAM = 'filter';

/**
 * Inertia's `page.url` is a root-relative path, which `URL` cannot parse on its
 * own. The base below only satisfies that constructor — it never reaches the
 * result, which is rebuilt from the path, query and hash alone.
 */
const URL_BASE = 'http://localhost';

/** Read the filter pinned on a URL, defaulting to the panel's own default. */
export function filterFromUrl(url: string): ThreadInboxFilter {
    const pinned = new URL(url, URL_BASE).searchParams.get(FILTER_PARAM);

    return (
        THREAD_INBOX_FILTERS.find((filter) => filter === pinned) ??
        DEFAULT_THREAD_INBOX_FILTER
    );
}

/**
 * Pin a filter onto a URL, leaving every other query param in place (`?nav=`, an
 * open `?thread=` panel). The default filter drops the param instead of spelling
 * it out, so the everyday URL stays clean.
 */
export function urlForThreadInboxFilter(
    url: string,
    filter: ThreadInboxFilter,
): string {
    const target = new URL(url, URL_BASE);

    if (filter === DEFAULT_THREAD_INBOX_FILTER) {
        target.searchParams.delete(FILTER_PARAM);
    } else {
        target.searchParams.set(FILTER_PARAM, filter);
    }

    return `${target.pathname}${target.search}${target.hash}`;
}
