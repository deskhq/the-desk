/**
 * The shared search-token model behind both the Cmd+K palette and the search
 * page. A raw query string is parsed once into a structured {@link SearchFilters}
 * — residual text plus single-valued `from` / `in` / `before` / `after` facets —
 * and serialized to the URL params the server understands, so the two entry
 * points drive one filter model and shared links reproduce the filtered view.
 *
 * `from:name` resolves to a user id and `in:#channel` to a channel id against the
 * loaded members/channels; `before:`/`after:` parse ISO dates. A token that does
 * not resolve (unknown member, absent channel, malformed date) falls back to
 * literal text, so nothing a user types is ever silently dropped.
 */

/**
 * The resolved, single-valued filter model. Absent facets are `null` to mirror
 * the server-echoed shape. Dates are `YYYY-MM-DD`.
 */
export type SearchFilters = {
    text: string;
    from: string | null;
    in: string | null;
    after: string | null;
    before: string | null;
};

/**
 * The members and channels a token resolves against. Kept minimal so both the
 * palette (`PersonRef`, `Channel`) and page (`UserData`, `ChannelData`) props
 * satisfy it without adaptation.
 */
export type TokenLookup = {
    members: ReadonlyArray<{ id: string; name: string }>;
    channels: ReadonlyArray<{ id: string; name: string; slug: string }>;
};

/** The URL params the search endpoints accept, empties omitted. */
export type SearchParams = {
    q?: string;
    from?: string;
    in?: string;
    after?: string;
    before?: string;
    scope?: string;
};

const TOKEN = /^(from|in|before|after):(.+)$/i;
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * An empty filter set — the starting point and the "cleared" state.
 */
export function emptyFilters(): SearchFilters {
    return { text: '', from: null, in: null, after: null, before: null };
}

/**
 * Parse a raw query string into structured filters, resolving recognized tokens
 * against the lookup. Later occurrences of a facet win (each is single-valued);
 * every unresolved word — token or plain — stays in `text`.
 */
export function parseSearchQuery(
    raw: string,
    lookup: TokenLookup,
): SearchFilters {
    const filters = emptyFilters();
    const residual: string[] = [];

    for (const word of raw.trim().split(/\s+/).filter(Boolean)) {
        const match = TOKEN.exec(word);

        if (match === null) {
            residual.push(word);
            continue;
        }

        const key = match[1].toLowerCase();
        const value = match[2];

        if (!applyToken(filters, key, value, lookup)) {
            residual.push(word);
        }
    }

    filters.text = residual.join(' ');

    return filters;
}

/**
 * Apply one recognized token to the filters in place, returning whether it
 * resolved. An unresolved token returns false so the caller keeps it as literal
 * text.
 */
function applyToken(
    filters: SearchFilters,
    key: string,
    value: string,
    lookup: TokenLookup,
): boolean {
    if (key === 'from') {
        const member = resolveMember(value, lookup.members);

        if (member === null) {
            return false;
        }

        filters.from = member;

        return true;
    }

    if (key === 'in') {
        const channel = resolveChannel(value, lookup.channels);

        if (channel === null) {
            return false;
        }

        filters.in = channel;

        return true;
    }

    const date = parseDate(value);

    if (date === null) {
        return false;
    }

    filters[key === 'before' ? 'before' : 'after'] = date;

    return true;
}

/**
 * Resolve `from:value` to a member id: a whole-word match on the display name
 * (so `maya` matches "Maya Chen"), else a full-name match. First match wins.
 */
function resolveMember(
    value: string,
    members: TokenLookup['members'],
): string | null {
    const needle = value.toLowerCase();

    const match = members.find((member) => {
        const name = member.name.toLowerCase();

        return name === needle || name.split(/\s+/).includes(needle);
    });

    return match?.id ?? null;
}

/**
 * Resolve `in:#value` (the leading `#` is optional) to a channel id by slug,
 * then by display name. First match wins.
 */
function resolveChannel(
    value: string,
    channels: TokenLookup['channels'],
): string | null {
    const needle = value.replace(/^#+/, '').toLowerCase();

    const match = channels.find(
        (channel) =>
            channel.slug.toLowerCase() === needle ||
            channel.name.toLowerCase() === needle,
    );

    return match?.id ?? null;
}

/**
 * Validate a `YYYY-MM-DD` token, returning it normalized or null when it is not
 * a real calendar date.
 */
function parseDate(value: string): string | null {
    if (!ISO_DATE.test(value)) {
        return null;
    }

    const timestamp = Date.parse(`${value}T00:00:00Z`);

    return Number.isNaN(timestamp) ? null : value;
}

/**
 * Serialize filters (and an optional scope) to URL params, omitting empties, so
 * a shared link reproduces the filtered view.
 */
export function filtersToParams(
    filters: SearchFilters,
    scope?: string,
): SearchParams {
    const params: SearchParams = {};

    if (filters.text !== '') {
        params.q = filters.text;
    }

    if (filters.from !== null) {
        params.from = filters.from;
    }

    if (filters.in !== null) {
        params.in = filters.in;
    }

    if (filters.after !== null) {
        params.after = filters.after;
    }

    if (filters.before !== null) {
        params.before = filters.before;
    }

    if (scope !== undefined && scope !== '') {
        params.scope = scope;
    }

    return params;
}
