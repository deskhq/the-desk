// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import { translate } from '@/lib/i18n';
import type { AuditExport } from '@/types';
import {
    auditExport,
    find,
    formatOptions,
    logTypeOptions,
    team,
} from './AuditExports.doubles';

/**
 * Covers the bottom half of the Exports page: the recent-exports list, the
 * four states a row can be in, and the poll that keeps a generating row
 * moving. Written against the page before it was split, so the suite staying
 * green across the move is the proof nothing changed (#993).
 */
const routerPost = vi.hoisted(() => vi.fn());
const routerReload = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    router: { post: routerPost, reload: routerReload },
    usePage: () => ({
        props: { auth: { user: { timezone: 'America/New_York' } } },
    }),
    Head: defineComponent({
        setup: () => () => h('div', { 'data-stub': 'Head' }),
    }),
}));

vi.mock('@lucide/vue', () => ({
    AlertCircle: { render: () => h('svg', { 'data-stub': 'AlertCircle' }) },
    Clock: { render: () => h('svg', { 'data-stub': 'Clock' }) },
    Download: { render: () => h('svg', { 'data-stub': 'Download' }) },
    Loader2: { render: () => h('svg', { 'data-stub': 'Loader2' }) },
    RotateCcw: { render: () => h('svg', { 'data-stub': 'RotateCcw' }) },
    ShieldCheck: { render: () => h('svg', { 'data-stub': 'ShieldCheck' }) },
    X: { render: () => h('svg', { 'data-stub': 'X' }) },
}));

vi.mock('@/routes/teams', () => ({
    edit: (slug: string) => `/teams/${slug}/edit`,
    index: () => '/teams',
}));

vi.mock('@/routes/teams/audit-exports', () => ({
    download: ([slug, id]: [string, string]) => ({
        url: `/teams/${slug}/audit-exports/${id}/download`,
    }),
    index: (slug: string) => `/teams/${slug}/audit-exports`,
    store: (slug: string) => ({ url: `/teams/${slug}/audit-exports` }),
}));

vi.mock('@/components/ui/date-picker', () => ({
    DatePicker: defineComponent({
        setup: () => () => h('button', { 'data-stub': 'DatePicker' }),
    }),
}));

import AuditExports from './AuditExports.vue';

const generating = auditExport({
    id: 'exp-pending',
    status: 'pending',
    isReady: false,
    expiresAt: null,
});

let app: App | null = null;

function mount(exports: AuditExport[] = []): HTMLElement {
    const host = document.createElement('div');
    document.body.append(host);

    app = createApp({
        render: () =>
            h(AuditExports, {
                team,
                exports,
                logTypeOptions,
                formatOptions,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    return host;
}

/** The meta line under a row's title, which carries the period and requester. */
function metaLine(host: HTMLElement, id = 'exp-1'): string {
    return find(host, `audit-export-row-${id}`)
        ?.querySelectorAll('p')[1]
        ?.textContent?.replace(/\s+/g, ' ')
        .trim() as string;
}

beforeEach(() => {
    routerPost.mockClear();
    routerReload.mockClear();
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

describe('the recent-exports section', () => {
    it('invites a first export when the list is empty', () => {
        const host = mount();

        expect(host.textContent).toContain('Recent exports');
        expect(find(host, 'audit-exports-empty')?.textContent).toContain(
            'No exports yet',
        );
        expect(find(host, 'audit-exports-empty')?.textContent).toContain(
            'Request an export above and it will appear here. Files stay available for 7 days.',
        );
        expect(find(host, 'audit-exports-polling')).toBeNull();
    });

    it('says it is refreshing only while an export is generating', () => {
        expect(
            find(mount([auditExport()]), 'audit-exports-polling'),
        ).toBeNull();

        document.body.innerHTML = '';

        expect(
            find(mount([generating]), 'audit-exports-polling')?.textContent,
        ).toContain('refreshing while an export is generating');
    });

    it('drops the empty state once an export exists', () => {
        const host = mount([auditExport()]);

        expect(find(host, 'audit-exports-empty')).toBeNull();
        expect(find(host, 'audit-export-row-exp-1')).not.toBeNull();
    });
});

describe('a row', () => {
    it('reads out the log, the format, the requester and when they asked', () => {
        const host = mount([auditExport()]);
        const row = find(host, 'audit-export-row-exp-1');

        expect(row?.querySelector('p')?.textContent).toContain(
            'Audit log · CSV',
        );
        expect(row?.querySelector('[data-stub="Clock"]')).not.toBeNull();
        expect(metaLine(host)).toContain('requested by Ada Lovelace');
        expect(metaLine(host)).toContain('Mar 4, 5:30 AM');
    });

    it('marks a security export with its own glyph', () => {
        const host = mount([
            auditExport({
                logType: 'security',
                logTypeLabel: 'Security events',
                formatLabel: 'JSON',
            }),
        ]);
        const row = find(host, 'audit-export-row-exp-1');

        expect(row?.querySelector('[data-stub="ShieldCheck"]')).not.toBeNull();
        expect(row?.querySelector('p')?.textContent).toContain(
            'Security events · JSON',
        );
    });

    it('names a requester who has since left as a former member', () => {
        const host = mount([auditExport({ requestedByName: null })]);

        expect(metaLine(host)).toContain('requested by a former member');
    });

    it('reads an unbounded period as all time', () => {
        expect(metaLine(mount([auditExport()]))).toContain('All time');
    });

    it('reads a one-sided period as open at that end', () => {
        expect(
            metaLine(mount([auditExport({ rangeEnd: '2026-03-31' })])),
        ).toContain('Until Mar 31, 2026');

        document.body.innerHTML = '';

        expect(
            metaLine(mount([auditExport({ rangeStart: '2026-03-01' })])),
        ).toContain('From Mar 1, 2026');
    });

    it('reads a closed period as its two ends', () => {
        const host = mount([
            auditExport({ rangeStart: '2026-03-01', rangeEnd: '2026-03-31' }),
        ]);

        expect(metaLine(host)).toContain('Mar 1, 2026 – Mar 31, 2026');
    });

    // The two instants sit either side of that year's clock change, so the
    // viewer's zone shifting the second one an hour is the zone being applied.
    it('tells a ready export when it lapses, in the viewer zone', () => {
        expect(metaLine(mount([auditExport()]))).toContain(
            'expires Mar 11, 6:30 AM',
        );
    });

    it('keeps the lapse date off a row that has no file to lose', () => {
        const host = mount([
            auditExport({ status: 'pending', isReady: false }),
        ]);

        expect(metaLine(host)).not.toContain('expires');
    });
});

describe('the state a row is in', () => {
    it('shows a spinner while the file is being built', () => {
        const host = mount([generating]);
        const badge = find(host, 'audit-export-status-generating');

        expect(badge?.textContent).toContain('Generating…');
        expect(badge?.querySelector('[data-stub="Loader2"]')).not.toBeNull();
        expect(find(host, 'audit-export-download-exp-pending')).toBeNull();
    });

    it('offers the file once it is ready', () => {
        const host = mount([auditExport()]);
        const link = find(host, 'audit-export-download-exp-1');

        expect(link?.tagName).toBe('A');
        expect(link?.getAttribute('href')).toBe(
            '/teams/acme/audit-exports/exp-1/download',
        );
        expect(link?.hasAttribute('download')).toBe(true);
        expect(link?.textContent).toContain('Download');
    });

    it('dims a lapsed export and takes the link away', () => {
        const host = mount([auditExport({ isReady: false, isExpired: true })]);

        expect(
            find(host, 'audit-export-status-expired')?.textContent,
        ).toContain('Expired');
        expect(
            find(host, 'audit-export-row-exp-1')?.classList.contains(
                'opacity-65',
            ),
        ).toBe(true);
        expect(find(host, 'audit-export-download-exp-1')).toBeNull();
    });

    it('offers a retry when the build failed', () => {
        const host = mount([
            auditExport({
                status: 'failed',
                isReady: false,
                expiresAt: null,
            }),
        ]);

        expect(find(host, 'audit-export-status-failed')?.textContent).toContain(
            'Failed',
        );
        expect(find(host, 'audit-export-retry-exp-1')?.textContent).toContain(
            'Retry',
        );
    });
});

describe('retrying a failed export', () => {
    const failed = auditExport({
        status: 'failed',
        isReady: false,
        logType: 'security',
        format: 'json',
        rangeStart: '2026-03-01',
        rangeEnd: '2026-03-31',
        expiresAt: null,
    });

    it('asks again for exactly what was asked for', () => {
        const host = mount([failed]);

        find(host, 'audit-export-retry-exp-1')?.click();

        expect(routerPost).toHaveBeenCalledWith(
            '/teams/acme/audit-exports',
            {
                log_type: 'security',
                format: 'json',
                range_start: '2026-03-01',
                range_end: '2026-03-31',
            },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('waits its turn while another export is generating', () => {
        const host = mount([failed, generating]);
        const retry = find(host, 'audit-export-retry-exp-1');

        expect(retry?.hasAttribute('disabled')).toBe(true);

        retry?.click();

        expect(routerPost).not.toHaveBeenCalled();
    });
});

describe('the poll', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('refreshes only the exports while one is generating', () => {
        mount([generating]);

        vi.advanceTimersByTime(4000);

        expect(routerReload).toHaveBeenCalledWith({
            async: true,
            preserveUrl: true,
            only: ['exports'],
        });
    });

    it('stays quiet when nothing is generating', () => {
        mount([auditExport()]);

        vi.advanceTimersByTime(12000);

        expect(routerReload).not.toHaveBeenCalled();
    });

    it('stops once the page is gone', () => {
        mount([generating]);

        app?.unmount();
        app = null;
        vi.advanceTimersByTime(12000);

        expect(routerReload).not.toHaveBeenCalled();
    });
});
