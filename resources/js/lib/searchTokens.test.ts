import { describe, expect, it } from 'vitest';
import {
    emptyFilters,
    filtersToParams,
    parseSearchQuery,
} from '@/lib/searchTokens';
import type { TokenLookup } from '@/lib/searchTokens';

const lookup: TokenLookup = {
    members: [
        { id: 'u-maya', name: 'Maya Chen' },
        { id: 'u-jonas', name: 'Jonas Weber' },
    ],
    channels: [
        { id: 'c-design', name: 'Design', slug: 'design' },
        { id: 'c-eng', name: 'Engineering', slug: 'engineering' },
    ],
};

describe('parseSearchQuery', () => {
    it('leaves a plain query as residual text', () => {
        expect(parseSearchQuery('launch checklist', lookup)).toEqual({
            ...emptyFilters(),
            text: 'launch checklist',
        });
    });

    it('resolves a from: token to a member id by first name', () => {
        const filters = parseSearchQuery('from:maya launch', lookup);

        expect(filters.from).toBe('u-maya');
        expect(filters.text).toBe('launch');
    });

    it('resolves an in:#channel token to a channel id by slug', () => {
        const filters = parseSearchQuery('in:#design launch', lookup);

        expect(filters.in).toBe('c-design');
        expect(filters.text).toBe('launch');
    });

    it('resolves several tokens and keeps the residual text', () => {
        const filters = parseSearchQuery(
            'from:maya in:#design launch checklist',
            lookup,
        );

        expect(filters).toEqual({
            text: 'launch checklist',
            from: 'u-maya',
            in: 'c-design',
            after: null,
            before: null,
            has: null,
        });
    });

    it('parses before: and after: dates', () => {
        const filters = parseSearchQuery(
            'after:2026-01-01 before:2026-06-30 report',
            lookup,
        );

        expect(filters.after).toBe('2026-01-01');
        expect(filters.before).toBe('2026-06-30');
        expect(filters.text).toBe('report');
    });

    it('promotes a has:file token into the attachment facet', () => {
        const filters = parseSearchQuery('has:file brief', lookup);

        expect(filters.has).toBe('file');
        expect(filters.text).toBe('brief');
    });

    it('is case-insensitive on the has: kind', () => {
        expect(parseSearchQuery('has:FILE', lookup).has).toBe('file');
    });

    it('keeps a has: kind nothing filters by as literal text', () => {
        // Only files are filterable today; `has:audio` is a search for those
        // words rather than a filter that would silently match nothing.
        const filters = parseSearchQuery('has:audio hello', lookup);

        expect(filters.has).toBeNull();
        expect(filters.text).toBe('has:audio hello');
    });

    it('keeps an unresolved member token as literal text', () => {
        const filters = parseSearchQuery('from:nobody hello', lookup);

        expect(filters.from).toBeNull();
        expect(filters.text).toBe('from:nobody hello');
    });

    it('keeps an unresolved channel token as literal text', () => {
        const filters = parseSearchQuery('in:#ghost hello', lookup);

        expect(filters.in).toBeNull();
        expect(filters.text).toBe('in:#ghost hello');
    });

    it('keeps a malformed date token as literal text', () => {
        const filters = parseSearchQuery('after:not-a-date hello', lookup);

        expect(filters.after).toBeNull();
        expect(filters.text).toBe('after:not-a-date hello');
    });

    it('rejects an impossible calendar date', () => {
        const filters = parseSearchQuery('before:2026-13-40 hello', lookup);

        expect(filters.before).toBeNull();
        expect(filters.text).toBe('before:2026-13-40 hello');
    });

    it('lets a later occurrence of a facet win', () => {
        const filters = parseSearchQuery('from:maya from:jonas', lookup);

        expect(filters.from).toBe('u-jonas');
        expect(filters.text).toBe('');
    });

    it('matches an in: token without the leading hash', () => {
        expect(parseSearchQuery('in:engineering', lookup).in).toBe('c-eng');
    });

    it('resolves an in: token by slug in preference to another channel name', () => {
        const ambiguous: TokenLookup = {
            members: [],
            channels: [
                { id: 'c-named', name: 'design', slug: 'zzz' },
                { id: 'c-slugged', name: 'other', slug: 'design' },
            ],
        };

        // "design" is both a name (c-named) and a slug (c-slugged); slug wins.
        expect(parseSearchQuery('in:design', ambiguous).in).toBe('c-slugged');
    });

    it('is case-insensitive on token keys', () => {
        expect(parseSearchQuery('FROM:maya', lookup).from).toBe('u-maya');
    });
});

describe('filtersToParams', () => {
    it('serializes only the set facets', () => {
        const filters = parseSearchQuery('from:maya in:#design launch', lookup);

        expect(filtersToParams(filters)).toEqual({
            q: 'launch',
            from: 'u-maya',
            in: 'c-design',
        });
    });

    it('serializes the attachment facet', () => {
        expect(
            filtersToParams(parseSearchQuery('has:file brief', lookup)),
        ).toEqual({ q: 'brief', has: 'file' });
    });

    it('omits empty text and appends a scope when given', () => {
        expect(filtersToParams(emptyFilters(), 'all')).toEqual({
            scope: 'all',
        });
    });

    it('omits the default team scope', () => {
        expect(filtersToParams(emptyFilters(), '')).toEqual({});
    });
});
