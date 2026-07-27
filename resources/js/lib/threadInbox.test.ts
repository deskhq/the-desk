import { describe, expect, it } from 'vitest';
import {
    DEFAULT_THREAD_INBOX_FILTER,
    filterFromUrl,
    THREAD_INBOX_FILTERS,
    urlForThreadInboxFilter,
} from '@/lib/threadInbox';

describe('filterFromUrl', () => {
    it('reads the pinned filter', () => {
        expect(filterFromUrl('/t/acme/c/general?nav=threads&filter=all')).toBe(
            'all',
        );
    });

    it('falls back to the default when the param is absent or unknown', () => {
        expect(filterFromUrl('/t/acme/c/general')).toBe(
            DEFAULT_THREAD_INBOX_FILTER,
        );
        expect(filterFromUrl('/t/acme/c/general?filter=everything')).toBe(
            DEFAULT_THREAD_INBOX_FILTER,
        );
    });

    it('offers exactly the two pills the panel draws', () => {
        expect(THREAD_INBOX_FILTERS).toEqual(['unread', 'all']);
    });
});

describe('urlForThreadInboxFilter', () => {
    it('pins a non-default filter and leaves every other param alone', () => {
        expect(
            urlForThreadInboxFilter(
                '/t/acme/c/general?nav=threads&thread=m-1',
                'all',
            ),
        ).toBe('/t/acme/c/general?nav=threads&thread=m-1&filter=all');
    });

    it('drops the param for the default filter rather than spelling it out', () => {
        expect(
            urlForThreadInboxFilter(
                '/t/acme/c/general?nav=threads&filter=all',
                'unread',
            ),
        ).toBe('/t/acme/c/general?nav=threads');
    });

    it('keeps the hash, which pagination and deep links ride on', () => {
        expect(urlForThreadInboxFilter('/t/acme/c/general#top', 'all')).toBe(
            '/t/acme/c/general?filter=all#top',
        );
    });
});
