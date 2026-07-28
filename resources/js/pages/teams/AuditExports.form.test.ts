// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import { translate } from '@/lib/i18n';
import {
    auditExport,
    find,
    formatOptions,
    logTypeOptions,
    team,
} from './AuditExports.doubles';

/**
 * Covers the top half of the Exports page: the log-type and format toggles,
 * the optional period with its backwards-range guard, and the request the
 * submit button sends. Written against the page before it was split, so the
 * suite staying green across the move is the proof nothing changed (#993).
 */
const routerPost = vi.hoisted(() => vi.fn());
const routerReload = vi.hoisted(() => vi.fn());

/** The day the next date-picker click reports back, set per test. */
const picked = vi.hoisted(() => ({ value: null as string | null }));

vi.mock('@inertiajs/vue3', () => ({
    router: { post: routerPost, reload: routerReload },
    usePage: () => ({
        props: { auth: { user: { timezone: 'America/New_York' } } },
    }),
    Head: defineComponent({
        props: { title: { type: String, default: '' } },
        setup: (props) => () =>
            h('div', { 'data-stub': 'Head', 'data-title': props.title }),
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

/**
 * Stands in for the calendar picker, which is exercised by its own suite and
 * by the browser tests. It reports the props the page binds and hands back the
 * day the test staged on the next click.
 */
vi.mock('@/components/ui/date-picker', () => ({
    DatePicker: defineComponent({
        props: {
            modelValue: { type: String, default: null },
            placeholder: { type: String, default: '' },
            fieldLabel: { type: String, default: '' },
            min: { type: String, default: null },
            invalid: { type: Boolean, default: false },
        },
        emits: ['update:modelValue'],
        setup:
            (props, { emit }) =>
            () =>
                h('button', {
                    'data-stub': 'DatePicker',
                    'data-value': props.modelValue ?? '',
                    'data-placeholder': props.placeholder,
                    'data-field-label': props.fieldLabel,
                    'data-min': props.min ?? '',
                    'data-invalid': String(props.invalid),
                    onClick: () => emit('update:modelValue', picked.value),
                }),
    }),
}));

import AuditExports from './AuditExports.vue';

let app: App | null = null;

function mount(props: Record<string, unknown> = {}): HTMLElement {
    const host = document.createElement('div');
    document.body.append(host);

    app = createApp({
        render: () =>
            h(AuditExports, {
                team,
                exports: [],
                logTypeOptions,
                formatOptions,
                ...props,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    return host;
}

/** Stage a day and click the picker that should receive it. */
async function pick(
    host: HTMLElement,
    selector: string,
    day: string | null,
): Promise<void> {
    picked.value = day;
    find(host, selector)?.click();
    await nextTick();
}

beforeEach(() => {
    routerPost.mockClear();
    routerReload.mockClear();
    picked.value = null;
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

describe('the page shell', () => {
    it('names the page and explains what an export is', () => {
        const host = mount();

        expect(
            host
                .querySelector('[data-stub="Head"]')
                ?.getAttribute('data-title'),
        ).toBe('Audit exports');
        expect(host.querySelector('h2')?.textContent).toContain(
            'Audit exports',
        );
        expect(host.textContent).toContain(
            'Export audit evidence for a review period. Files are available to team admins for 7 days.',
        );
        expect(host.textContent).toContain(
            "One log, one format, one file. You'll get an email when it's ready.",
        );
    });

    it('footnotes the UTC caveat and links to the operator docs', () => {
        const host = mount();
        const link = find(host, 'audit-export-docs-link');

        expect(host.textContent).toContain(
            "Timestamps are exported in UTC. Security-event exports cover the current members' account-level events for the period, including activity outside this team.",
        );
        expect(link?.textContent?.trim()).toBe('Learn more');
        expect(link?.getAttribute('href')).toBe(
            'https://docs.thedeskhq.app/reference/security-and-compliance/#audit-log-exports',
        );
        expect(link?.getAttribute('target')).toBe('_blank');
        expect(link?.getAttribute('rel')).toBe('noopener noreferrer');
    });
});

describe('the log-type field', () => {
    it('offers every log, presses the first and marks each with its icon', () => {
        const host = mount();
        const group = host.querySelector('[role="group"]');

        expect(group?.getAttribute('aria-label')).toBe('Log');

        const audit = find(host, 'audit-export-log-audit');
        const security = find(host, 'audit-export-log-security');

        expect(audit?.textContent?.trim()).toBe('Audit log');
        expect(audit?.getAttribute('aria-pressed')).toBe('true');
        expect(audit?.querySelector('[data-stub="Clock"]')).not.toBeNull();

        expect(security?.textContent?.trim()).toBe('Security events');
        expect(security?.getAttribute('aria-pressed')).toBe('false');
        expect(
            security?.querySelector('[data-stub="ShieldCheck"]'),
        ).not.toBeNull();
    });

    it('moves the pressed state to the log that was picked', async () => {
        const host = mount();

        find(host, 'audit-export-log-security')?.click();
        await nextTick();

        expect(
            find(host, 'audit-export-log-audit')?.getAttribute('aria-pressed'),
        ).toBe('false');
        expect(
            find(host, 'audit-export-log-security')?.getAttribute(
                'aria-pressed',
            ),
        ).toBe('true');
    });
});

describe('the format field', () => {
    it('offers every format and presses the first', () => {
        const host = mount();
        const group = host.querySelectorAll('[role="group"]')[1];

        expect(group?.getAttribute('aria-label')).toBe('Format');
        expect(find(host, 'audit-export-format-csv')?.textContent?.trim()).toBe(
            'CSV',
        );
        expect(
            find(host, 'audit-export-format-csv')?.getAttribute('aria-pressed'),
        ).toBe('true');
        expect(
            find(host, 'audit-export-format-json')?.getAttribute(
                'aria-pressed',
            ),
        ).toBe('false');
    });

    it('moves the pressed state to the format that was picked', async () => {
        const host = mount();

        find(host, 'audit-export-format-json')?.click();
        await nextTick();

        expect(
            find(host, 'audit-export-format-csv')?.getAttribute('aria-pressed'),
        ).toBe('false');
        expect(
            find(host, 'audit-export-format-json')?.getAttribute(
                'aria-pressed',
            ),
        ).toBe('true');
    });
});

describe('the period field', () => {
    it('labels both ends and marks the period optional', () => {
        const host = mount();
        const start = find(host, 'audit-export-range-start');
        const end = find(host, 'audit-export-range-end');

        expect(host.textContent).toContain('Period');
        expect(host.textContent).toContain('optional');
        expect(start?.getAttribute('data-placeholder')).toBe('Start date');
        expect(start?.getAttribute('data-field-label')).toBe('Start date');
        expect(end?.getAttribute('data-placeholder')).toBe('End date');
        expect(end?.getAttribute('data-field-label')).toBe('End date');
    });

    it('floors the end of the period at the start once one is picked', async () => {
        const host = mount();

        expect(
            find(host, 'audit-export-range-end')?.getAttribute('data-min'),
        ).toBe('');

        await pick(host, 'audit-export-range-start', '2026-03-01');

        expect(
            find(host, 'audit-export-range-start')?.getAttribute('data-value'),
        ).toBe('2026-03-01');
        expect(
            find(host, 'audit-export-range-end')?.getAttribute('data-min'),
        ).toBe('2026-03-01');
    });

    it('offers to clear the period only once one end is set', async () => {
        const host = mount();

        expect(find(host, 'audit-export-range-clear')).toBeNull();

        await pick(host, 'audit-export-range-end', '2026-03-31');

        const clear = find(host, 'audit-export-range-clear');
        expect(clear?.getAttribute('aria-label')).toBe('Clear period');

        clear?.click();
        await nextTick();

        expect(find(host, 'audit-export-range-clear')).toBeNull();
        expect(
            find(host, 'audit-export-range-end')?.getAttribute('data-value'),
        ).toBe('');
    });

    it('reads a cleared day back as no day', async () => {
        const host = mount();

        await pick(host, 'audit-export-range-start', '2026-03-01');
        await pick(host, 'audit-export-range-start', null);

        expect(
            find(host, 'audit-export-range-start')?.getAttribute('data-value'),
        ).toBe('');
        expect(find(host, 'audit-export-range-clear')).toBeNull();
    });

    it('rejects an end before the start and blocks the request', async () => {
        const host = mount();

        await pick(host, 'audit-export-range-start', '2026-03-31');
        await pick(host, 'audit-export-range-end', '2026-03-01');

        expect(find(host, 'audit-export-range-error')?.textContent).toContain(
            'End date must be on or after the start date.',
        );
        expect(
            find(host, 'audit-export-range-end')?.getAttribute('data-invalid'),
        ).toBe('true');
        expect(
            find(host, 'audit-export-submit')?.hasAttribute('disabled'),
        ).toBe(true);

        find(host, 'audit-export-submit')?.click();
        await nextTick();

        expect(routerPost).not.toHaveBeenCalled();
    });
});

describe('requesting an export', () => {
    it('posts the picked log, format and period, and clears while in flight', async () => {
        const host = mount();

        find(host, 'audit-export-log-security')?.click();
        find(host, 'audit-export-format-json')?.click();
        await pick(host, 'audit-export-range-start', '2026-03-01');
        await pick(host, 'audit-export-range-end', '2026-03-31');

        expect(find(host, 'audit-export-range-error')).toBeNull();

        find(host, 'audit-export-submit')?.click();
        await nextTick();

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

    it('sends no period when neither end was picked', async () => {
        const host = mount();

        find(host, 'audit-export-submit')?.click();
        await nextTick();

        expect(routerPost.mock.calls[0]?.[1]).toEqual({
            log_type: 'audit',
            format: 'csv',
            range_start: null,
            range_end: null,
        });
    });

    it('falls back to the audit log as CSV when the server offers no options', async () => {
        const host = mount({ logTypeOptions: [], formatOptions: [] });

        find(host, 'audit-export-submit')?.click();
        await nextTick();

        expect(routerPost.mock.calls[0]?.[1]).toMatchObject({
            log_type: 'audit',
            format: 'csv',
        });
    });

    it('holds the button down for the round trip', async () => {
        const host = mount();

        find(host, 'audit-export-submit')?.click();
        const options = routerPost.mock.calls[0]?.[2];

        options.onStart();
        await nextTick();
        expect(
            find(host, 'audit-export-submit')?.hasAttribute('disabled'),
        ).toBe(true);

        options.onFinish();
        await nextTick();
        expect(
            find(host, 'audit-export-submit')?.hasAttribute('disabled'),
        ).toBe(false);
    });

    it('refuses a second request while one is still generating', async () => {
        const host = mount({
            exports: [auditExport({ status: 'pending', isReady: false })],
        });

        expect(
            find(host, 'audit-export-submit')?.hasAttribute('disabled'),
        ).toBe(true);

        find(host, 'audit-export-submit')?.click();
        await nextTick();

        expect(routerPost).not.toHaveBeenCalled();
    });
});
