// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import { translate } from '@/lib/i18n';
import type {
    AnalyticsRangeOption,
    AnalyticsStat,
    Team,
    WorkspaceAnalytics,
} from '@/types';

/**
 * Covers the top half of the analytics dashboard: the heading with its
 * admins-only badge, the range toggle and the request it sends, and the four
 * stat tiles with the comparison line and tone each delta produces. Written
 * against the page before it was split, so the suite staying green across the
 * move is the proof nothing changed (#990).
 */
const routerGet = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    router: { get: routerGet },
    Head: defineComponent({
        props: { title: { type: String, default: '' } },
        setup: (props) => () =>
            h('div', { 'data-stub': 'Head', 'data-title': props.title }),
    }),
}));

vi.mock('@lucide/vue', () => ({
    ArrowDown: { render: () => h('svg', { 'data-stub': 'ArrowDown' }) },
    ArrowUp: { render: () => h('svg', { 'data-stub': 'ArrowUp' }) },
}));

vi.mock('@/routes/teams', () => ({
    edit: (slug: string) => `/teams/${slug}/edit`,
    index: () => '/teams',
}));

vi.mock('@/routes/teams/analytics', () => ({
    index: (slug: string) => ({ url: `/teams/${slug}/analytics` }),
}));

/** Renders a child's default slot, so a stubbed wrapper stays transparent. */
function passthrough(tag = 'div') {
    return defineComponent({
        setup:
            (_props, { slots }) =>
            () =>
                h(tag, slots.default?.()),
    });
}

vi.mock('@unovis/ts', () => ({ CurveType: { MonotoneX: 'monotoneX' } }));

vi.mock('@unovis/vue', () => ({
    VisArea: passthrough(),
    VisAxis: passthrough(),
    VisGroupedBar: passthrough(),
    VisLine: passthrough(),
    VisXYContainer: passthrough(),
}));

vi.mock('@/components/ui/chart', () => ({
    ChartContainer: passthrough(),
    ChartCrosshair: passthrough(),
    ChartTooltip: passthrough(),
    ChartTooltipContent: passthrough(),
    componentToString: () => '',
}));

import Analytics from './Analytics.vue';

function stat(overrides: Partial<AnalyticsStat> = {}): AnalyticsStat {
    return {
        value: 0,
        total: null,
        delta: null,
        deltaPercent: null,
        secondary: null,
        ...overrides,
    };
}

function analytics(
    overrides: Partial<WorkspaceAnalytics> = {},
): WorkspaceAnalytics {
    return {
        range: '30d',
        days: 30,
        activeMembers: stat({ value: 12, total: 20, delta: 3 }),
        messagesPerDay: stat({ value: 48, deltaPercent: -12 }),
        messagesSent: stat({ value: 1440, secondary: 210 }),
        activeChannels: stat({ value: 6, total: 9, delta: 0 }),
        messagesByDay: [],
        topChannels: [],
        memberGrowth: [],
        topContributors: [],
        ...overrides,
    };
}

const team: Team = {
    id: 't-1',
    name: 'Acme Corp',
    slug: 'acme',
    isPersonal: false,
    role: 'owner',
    membersCount: 12,
    unreadCount: 0,
    mentionCount: 0,
};

const rangeOptions: AnalyticsRangeOption[] = [
    { value: '7d', label: '7 days', days: 7 },
    { value: '30d', label: '30 days', days: 30 },
    { value: '90d', label: '90 days', days: 90 },
];

let app: App | null = null;

function mount(props: Record<string, unknown> = {}) {
    const host = document.createElement('div');
    document.body.append(host);

    app = createApp({
        render: () =>
            h(Analytics, {
                team,
                analytics: analytics(),
                range: '30d',
                rangeOptions,
                ...props,
            }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    return host;
}

function find(host: HTMLElement, selector: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}

beforeEach(() => {
    routerGet.mockClear();
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

describe('the heading', () => {
    it('names the page, flags it as admin-only and scopes it to the team', () => {
        const host = mount();

        expect(host.querySelector('h2')?.textContent).toContain('Analytics');
        expect(host.textContent).toContain('Admins only');
        expect(host.textContent).toContain(
            'Workspace activity for Acme Corp, scoped to this team.',
        );
        expect(
            host
                .querySelector('[data-stub="Head"]')
                ?.getAttribute('data-title'),
        ).toBe('Analytics');
    });
});

describe('the range toggle', () => {
    it('offers every range and presses the active one', () => {
        const host = mount();
        const group = find(host, 'analytics-range');

        expect(group?.getAttribute('role')).toBe('group');
        expect(group?.getAttribute('aria-label')).toBe('Time range');

        for (const option of rangeOptions) {
            const button = find(host, `analytics-range-${option.value}`);

            expect(button?.textContent?.trim()).toBe(option.label);
            expect(button?.getAttribute('aria-pressed')).toBe(
                String(option.value === '30d'),
            );
        }
    });

    it('replaces history when another range is picked', () => {
        const host = mount();

        find(host, 'analytics-range-7d')?.click();

        expect(routerGet).toHaveBeenCalledWith(
            '/teams/acme/analytics',
            { range: '7d' },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    });

    it('sends nothing when the active range is picked again', () => {
        const host = mount();

        find(host, 'analytics-range-30d')?.click();

        expect(routerGet).not.toHaveBeenCalled();
    });
});

describe('the stat tiles', () => {
    it('renders each headline stat with its meta and comparison line', () => {
        const host = mount();

        const members = find(host, 'analytics-stat-members');
        expect(members?.textContent).toContain('Active members');
        expect(members?.textContent).toContain('12');
        expect(members?.textContent).toContain('of 20');
        expect(members?.textContent).toContain('+3 vs previous 30 days');

        const perDay = find(host, 'analytics-stat-perDay');
        expect(perDay?.textContent).toContain('Messages / day');
        expect(perDay?.textContent).toContain('48');
        expect(perDay?.textContent).toContain('avg');
        expect(perDay?.textContent).toContain('-12% vs previous 30 days');

        const sent = find(host, 'analytics-stat-sent');
        expect(sent?.textContent).toContain('Messages sent');
        expect(sent?.textContent).toContain('1,440');
        expect(sent?.textContent).toContain('30 days');
        expect(sent?.textContent).toContain('210 in threads');

        const channels = find(host, 'analytics-stat-channels');
        expect(channels?.textContent).toContain('Active channels');
        expect(channels?.textContent).toContain('of 9');
        expect(channels?.textContent).toContain(
            'No change vs previous 30 days',
        );
    });

    it('falls back to zero when a total or a thread count is missing', () => {
        const host = mount({
            analytics: analytics({
                activeMembers: stat({ value: 4, total: null, delta: 1 }),
                messagesSent: stat({ value: 9, secondary: null }),
            }),
        });

        expect(find(host, 'analytics-stat-members')?.textContent).toContain(
            'of 0',
        );
        expect(find(host, 'analytics-stat-sent')?.textContent).toContain(
            '0 in threads',
        );
    });

    it('reads a missing percentage as no previous activity', () => {
        const host = mount({
            analytics: analytics({
                messagesPerDay: stat({ value: 3, deltaPercent: null }),
            }),
        });

        expect(find(host, 'analytics-stat-perDay')?.textContent).toContain(
            'No previous activity',
        );
    });

    it('reads a flat percentage as no change', () => {
        const host = mount({
            analytics: analytics({
                messagesPerDay: stat({ value: 3, deltaPercent: 0 }),
            }),
        });

        expect(find(host, 'analytics-stat-perDay')?.textContent).toContain(
            'No change vs previous 30 days',
        );
    });

    it('signs a positive percentage', () => {
        const host = mount({
            analytics: analytics({
                messagesPerDay: stat({ value: 3, deltaPercent: 12 }),
            }),
        });

        expect(find(host, 'analytics-stat-perDay')?.textContent).toContain(
            '+12% vs previous 30 days',
        );
    });

    it('signs a negative absolute delta', () => {
        const host = mount({
            analytics: analytics({
                activeMembers: stat({ value: 4, total: 9, delta: -2 }),
            }),
        });

        expect(find(host, 'analytics-stat-members')?.textContent).toContain(
            '-2 vs previous 30 days',
        );
    });

    it('tones a rise green, a fall amber and a flat line muted', () => {
        const host = mount({
            analytics: analytics({
                activeMembers: stat({ value: 4, total: 9, delta: 2 }),
                messagesPerDay: stat({ value: 3, deltaPercent: -4 }),
                activeChannels: stat({ value: 1, total: 2, delta: 0 }),
            }),
        });

        expect(find(host, 'analytics-stat-members')?.innerHTML).toContain(
            'text-emerald-700 dark:text-emerald-500',
        );
        expect(find(host, 'analytics-stat-perDay')?.innerHTML).toContain(
            'text-amber-700 dark:text-amber-500',
        );
        expect(find(host, 'analytics-stat-channels')?.innerHTML).toContain(
            'text-muted-foreground',
        );
    });

    it('points the arrow the way the delta went, and drops it when flat', () => {
        const host = mount({
            analytics: analytics({
                activeMembers: stat({ value: 4, total: 9, delta: 2 }),
                messagesPerDay: stat({ value: 3, deltaPercent: -4 }),
                activeChannels: stat({ value: 1, total: 2, delta: 0 }),
            }),
        });

        expect(
            find(host, 'analytics-stat-members')?.querySelector(
                '[data-stub="ArrowUp"]',
            ),
        ).not.toBeNull();
        expect(
            find(host, 'analytics-stat-perDay')?.querySelector(
                '[data-stub="ArrowDown"]',
            ),
        ).not.toBeNull();
        expect(
            find(host, 'analytics-stat-channels')?.querySelector('svg'),
        ).toBeNull();
        expect(
            find(host, 'analytics-stat-sent')?.querySelector('svg'),
        ).toBeNull();
    });
});

describe('the storage read-out', () => {
    it('stays off the page while no workspace quota is configured', () => {
        expect(find(mount(), 'analytics-storage')).toBeNull();
        expect(find(mount({ storage: null }), 'analytics-storage')).toBeNull();
    });

    it('reports the usage, the quota and the space left', () => {
        const host = mount({
            storage: {
                usedBytes: 1024 * 1024,
                quotaBytes: 4 * 1024 * 1024,
                percent: 25,
            },
        });

        expect(find(host, 'analytics-storage')?.textContent).toContain(
            'Storage',
        );
        expect(find(host, 'analytics-storage-percent')?.textContent).toContain(
            '25% used',
        );
        expect(find(host, 'analytics-storage-size')?.textContent).toContain(
            '1 MB of 4 MB',
        );
        expect(
            find(host, 'analytics-storage-remaining')?.textContent,
        ).toContain('3 MB free');
    });

    it('exposes the bar as a labelled progressbar', () => {
        const host = mount({
            storage: {
                usedBytes: 3 * 1024 * 1024,
                quotaBytes: 4 * 1024 * 1024,
                percent: 75,
            },
        });

        const bar = find(host, 'analytics-storage')?.querySelector(
            '[role="progressbar"]',
        );

        expect(bar?.getAttribute('aria-label')).toBe('Storage used');
        expect(bar?.getAttribute('aria-valuenow')).toBe('75');
        expect(bar?.getAttribute('aria-valuemin')).toBe('0');
        expect(bar?.getAttribute('aria-valuemax')).toBe('100');
        expect(bar?.getAttribute('aria-valuetext')).toBe('3 MB of 4 MB');
    });
});
