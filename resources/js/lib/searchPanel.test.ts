import { describe, expect, it } from 'vitest';
import {
    searchFingerprint,
    searchParamsFromUrl,
    urlForSearchParams,
} from '@/lib/searchPanel';

describe('searchParamsFromUrl', () => {
    it('reads the criteria a shared link carries', () => {
        expect(
            searchParamsFromUrl(
                '/t/acme/c/general?nav=search&q=brief&from=u-1&in=c-2&after=2026-07-01&before=2026-07-20&scope=all',
            ),
        ).toEqual({
            q: 'brief',
            from: 'u-1',
            in: 'c-2',
            after: '2026-07-01',
            before: '2026-07-20',
            scope: 'all',
        });
    });

    it('leaves every facet unset on a plain workspace url', () => {
        expect(searchParamsFromUrl('/t/acme/c/general')).toEqual({
            q: undefined,
            from: undefined,
            in: undefined,
            after: undefined,
            before: undefined,
            scope: undefined,
        });
    });

    it('trims the query and treats a blank one as absent', () => {
        expect(searchParamsFromUrl('/t/acme?q=%20brief%20').q).toBe('brief');
        expect(searchParamsFromUrl('/t/acme?q=%20%20').q).toBeUndefined();
    });

    // The server drops these too; were the two to disagree, the panel would keep
    // asking for a URL the server keeps resolving differently.
    it('drops a value longer than the engine accepts', () => {
        const long = 'a'.repeat(256);

        expect(searchParamsFromUrl(`/t/acme?q=${long}`).q).toBeUndefined();
        expect(
            searchParamsFromUrl(`/t/acme?from=${long}`).from,
        ).toBeUndefined();
    });

    it('drops a date facet that is not a plain calendar day', () => {
        expect(
            searchParamsFromUrl('/t/acme?after=not-a-date').after,
        ).toBeUndefined();
        expect(
            searchParamsFromUrl('/t/acme?before=yesterday').before,
        ).toBeUndefined();
        expect(searchParamsFromUrl('/t/acme?after=2026-07-01').after).toBe(
            '2026-07-01',
        );
    });

    it('narrows an unknown scope back to the current team', () => {
        expect(
            searchParamsFromUrl('/t/acme?scope=sideways').scope,
        ).toBeUndefined();
        expect(searchParamsFromUrl('/t/acme?scope=all').scope).toBe('all');
    });
});

describe('urlForSearchParams', () => {
    it('pins the criteria while leaving the rest of the url alone', () => {
        expect(
            urlForSearchParams('/t/acme/c/general?nav=search&thread=m-9', {
                q: 'brief',
                from: 'u-1',
            }),
        ).toBe('/t/acme/c/general?nav=search&thread=m-9&q=brief&from=u-1');
    });

    it('drops the param of a facet that is no longer applied', () => {
        expect(
            urlForSearchParams('/t/acme?nav=search&q=brief&from=u-1', {
                q: 'brief',
            }),
        ).toBe('/t/acme?nav=search&q=brief');
    });

    it('drops an empty query rather than spelling it out', () => {
        expect(urlForSearchParams('/t/acme?q=brief', { q: '' })).toBe(
            '/t/acme',
        );
    });

    it('keeps the hash a jump link carries', () => {
        expect(urlForSearchParams('/t/acme#main', { q: 'brief' })).toBe(
            '/t/acme?q=brief#main',
        );
    });
});

describe('searchFingerprint', () => {
    // The client writes absent facets as `undefined` and leaves the default
    // scope off the URL entirely; the server echoes `null` and always names the
    // scope. The same search has to digest identically through both shapes.
    it('reads the client and server shapes of the same criteria alike', () => {
        expect(
            searchFingerprint({ q: 'brief', from: 'u-1', scope: undefined }),
        ).toBe(
            searchFingerprint({
                q: 'brief',
                from: 'u-1',
                in: null,
                after: null,
                before: null,
                scope: 'team',
            }),
        );
    });

    it('separates criteria that differ in any one facet', () => {
        const base = { q: 'brief' };

        expect(searchFingerprint(base)).not.toBe(
            searchFingerprint({ ...base, from: 'u-1' }),
        );
        expect(searchFingerprint(base)).not.toBe(
            searchFingerprint({ ...base, scope: 'all' }),
        );
        expect(searchFingerprint(base)).not.toBe(
            searchFingerprint({ q: 'briefing' }),
        );
    });

    it('is not fooled by a value that looks like the separator', () => {
        expect(searchFingerprint({ q: 'a', from: 'b' })).not.toBe(
            searchFingerprint({ q: 'a","b' }),
        );
    });
});
