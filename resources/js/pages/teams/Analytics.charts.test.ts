// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import { formatCalendarDate, formatMonthLabel } from '@/lib/datetime';
import { translate } from '@/lib/i18n';
import type {
    AnalyticsRangeOption,
    AnalyticsStat,
    Team,
    WorkspaceAnalytics,
} from '@/types';

/**
 * Covers the bottom half of the analytics dashboard: the two charts and the
 * shape they hand @unovis (series, domains, tick values, tick labels), and the
 * two ranked lists with their bar geometry, initials and empty states. Written
 * against the page before it was split, so the suite staying green across the
 * move is the proof nothing changed (#990).
 */
type StubProps = Record<string, unknown>;

const rendered = vi.hoisted(() => new Map<string, StubProps[]>());

/**
 * A stub that records the props each render passes it, so the chart wiring can
 * be asserted without @unovis touching the DOM.
 */
function recorder(name: string) {
    return defineComponent({
        name,
        setup: (_props, { attrs, slots }) => {
            return () => {
                const calls = rendered.get(name) ?? [];
                calls.push({ ...attrs });
                rendered.set(name, calls);

                return h('div', { 'data-stub': name }, slots.default?.());
            };
        },
    });
}

vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn() },
    Head: defineComponent({ setup: () => () => h('div') }),
}));

vi.mock('@lucide/vue', () => ({
    ArrowDown: { render: () => h('svg') },
    ArrowUp: { render: () => h('svg') },
}));

vi.mock('@/routes/teams', () => ({
    edit: (slug: string) => `/teams/${slug}/edit`,
    index: () => '/teams',
}));

vi.mock('@/routes/teams/analytics', () => ({
    index: (slug: string) => ({ url: `/teams/${slug}/analytics` }),
}));

vi.mock('@unovis/ts', () => ({ CurveType: { MonotoneX: 'monotoneX' } }));

vi.mock('@unovis/vue', () => ({
    VisArea: recorder('VisArea'),
    VisAxis: recorder('VisAxis'),
    VisGroupedBar: recorder('VisGroupedBar'),
    VisLine: recorder('VisLine'),
    VisXYContainer: recorder('VisXYContainer'),
}));

vi.mock('@/components/ui/chart', () => ({
    ChartContainer: recorder('ChartContainer'),
    ChartCrosshair: recorder('ChartCrosshair'),
    ChartTooltip: recorder('ChartTooltip'),
    ChartTooltipContent: { render: () => h('div') },
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
        activeMembers: stat(),
        messagesPerDay: stat(),
        messagesSent: stat(),
        activeChannels: stat(),
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
    { value: '30d', label: '30 days', days: 30 },
];

/** Noon local time, so the weekday of a fixture date never depends on the zone. */
function day(date: string): string {
    return `${date}T12:00:00`;
}

/** A messages-per-day series of `count` consecutive days from 2026-07-01. */
function dailySeries(count: number) {
    return Array.from({ length: count }, (_point, index) => ({
        date: day(
            new Date(Date.UTC(2026, 6, 1 + index)).toISOString().slice(0, 10),
        ),
        count: index,
    }));
}

let app: App | null = null;

/**
 * Mounts the page and stops before the charts swap in: they render client-only,
 * so the skeleton holds the layout until the mounted flag lands on the next
 * tick.
 */
function mountUnsettled(props: Record<string, unknown> = {}) {
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

async function mount(props: Record<string, unknown> = {}) {
    const host = mountUnsettled(props);
    await nextTick();

    return host;
}

function find(host: HTMLElement, selector: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}

/** The props of the nth render of a recorded stub. */
function propsOf(name: string, occurrence = 0): StubProps {
    const calls = rendered.get(name) ?? [];

    expect(calls.length).toBeGreaterThan(occurrence);

    return calls[occurrence];
}

beforeEach(() => {
    rendered.clear();
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

describe('the charts', () => {
    it('hold the layout with a skeleton until they mount', () => {
        const host = mountUnsettled({
            analytics: analytics({ messagesByDay: dailySeries(3) }),
        });

        expect(find(host, 'analytics-messages-chart')).toBeNull();
        expect(find(host, 'analytics-growth-chart')).toBeNull();
        expect(host.querySelectorAll('.animate-pulse')).toHaveLength(2);
    });
});

describe('the messages-per-day chart', () => {
    it('plots the daily series against an index-based x domain', async () => {
        const host = await mount({
            analytics: analytics({ messagesByDay: dailySeries(3) }),
        });

        expect(find(host, 'analytics-messages-chart')).not.toBeNull();

        const container = propsOf('VisXYContainer');
        expect(container.data).toEqual([
            { date: new Date(day('2026-07-01')), count: 0 },
            { date: new Date(day('2026-07-02')), count: 1 },
            { date: new Date(day('2026-07-03')), count: 2 },
        ]);
        expect(container['x-domain']).toEqual([-0.5, 2.5]);
        expect(container['y-domain']).toEqual([0, undefined]);
    });

    it('shades weekend bars and leaves weekdays in the series colour', async () => {
        await mount({
            analytics: analytics({ messagesByDay: dailySeries(1) }),
        });

        const color = propsOf('VisGroupedBar').color as (point: {
            date: Date;
        }) => string;

        expect(color({ date: new Date(day('2026-07-04')) })).toBe(
            'var(--chart-5)',
        );
        expect(color({ date: new Date(day('2026-07-05')) })).toBe(
            'var(--chart-5)',
        );
        expect(color({ date: new Date(day('2026-07-06')) })).toBe(
            'var(--chart-1)',
        );
    });

    it('thins the day ticks to about six, always ending on the last day', async () => {
        await mount({
            analytics: analytics({ messagesByDay: dailySeries(30) }),
        });

        expect(propsOf('VisAxis')['tick-values']).toEqual([
            0, 5, 10, 15, 20, 25, 29,
        ]);
    });

    it('keeps a single tick when there is one day or none', async () => {
        await mount({
            analytics: analytics({ messagesByDay: dailySeries(1) }),
        });
        expect(propsOf('VisAxis')['tick-values']).toEqual([0]);

        rendered.clear();
        app?.unmount();
        app = null;

        await mount({ analytics: analytics({ messagesByDay: [] }) });
        expect(propsOf('VisAxis')['tick-values']).toEqual([0]);
    });

    it('labels a day tick with its calendar date, and an unknown one with nothing', async () => {
        await mount({
            analytics: analytics({ messagesByDay: dailySeries(3) }),
        });

        const label = propsOf('VisAxis')['tick-format'] as (
            index: number,
        ) => string;

        expect(label(0)).toBe(formatCalendarDate(new Date(day('2026-07-01'))));
        expect(label(2)).toBe(formatCalendarDate(new Date(day('2026-07-03'))));
        expect(label(9)).toBe('');
    });
});

describe('the member-growth chart', () => {
    it('plots the cumulative totals against one tick per month', async () => {
        const host = await mount({
            analytics: analytics({
                memberGrowth: [
                    { month: day('2026-05-01'), total: 4 },
                    { month: day('2026-06-01'), total: 7 },
                ],
            }),
        });

        expect(find(host, 'analytics-growth-chart')).not.toBeNull();

        const container = propsOf('VisXYContainer', 1);
        expect(container.data).toEqual([
            { date: new Date(day('2026-05-01')), total: 4 },
            { date: new Date(day('2026-06-01')), total: 7 },
        ]);
        expect(container['y-domain']).toEqual([0, undefined]);

        const axis = propsOf('VisAxis', 2);
        expect(axis['tick-values']).toEqual([0, 1]);

        const label = axis['tick-format'] as (index: number) => string;
        expect(label(0)).toBe(formatMonthLabel(new Date(day('2026-05-01'))));
        expect(label(5)).toBe('');
    });
});

describe('the most-active channels', () => {
    it('says so when nothing was posted in the window', async () => {
        const host = await mount();

        expect(find(host, 'analytics-channels-empty')?.textContent).toContain(
            'No channel activity in this window.',
        );
        expect(find(host, 'analytics-channels')).toBeNull();
    });

    it('scales each bar against the busiest channel and ranks its colour', async () => {
        const host = await mount({
            analytics: analytics({
                topChannels: [
                    { id: 'c-1', name: 'general', count: 40 },
                    { id: 'c-2', name: 'random', count: 20 },
                    { id: 'c-3', name: 'design', count: 10 },
                    { id: 'c-4', name: 'ops', count: 4 },
                ],
            }),
        });

        const bars = find(
            host,
            'analytics-channels',
        )?.querySelectorAll<HTMLElement>('li > div > div');

        expect(bars?.[0].style.width).toBe('100%');
        expect(bars?.[0].style.background).toBe('var(--chart-1)');
        expect(bars?.[1].style.width).toBe('50%');
        expect(bars?.[1].style.background).toBe('var(--chart-3)');
        expect(bars?.[2].style.width).toBe('25%');
        expect(bars?.[2].style.background).toBe('var(--chart-3)');
        expect(bars?.[3].style.width).toBe('10%');
        expect(bars?.[3].style.background).toBe('var(--chart-4)');

        expect(find(host, 'analytics-channels')?.textContent).toContain(
            'general',
        );
        expect(find(host, 'analytics-channels')?.textContent).toContain('40');
    });

    it('keeps a bar visible when every channel is silent', async () => {
        const host = await mount({
            analytics: analytics({
                topChannels: [{ id: 'c-1', name: 'general', count: 0 }],
            }),
        });

        const bar = find(
            host,
            'analytics-channels',
        )?.querySelector<HTMLElement>('li > div > div');

        expect(bar?.style.width).toBe('0%');
    });
});

describe('the top contributors', () => {
    it('says so when nobody posted in the window', async () => {
        const host = await mount();

        expect(
            find(host, 'analytics-contributors-empty')?.textContent,
        ).toContain('No contributors in this window.');
        expect(find(host, 'analytics-contributors')).toBeNull();
    });

    it('lists each person with their initials and message count', async () => {
        const host = await mount({
            analytics: analytics({
                topContributors: [
                    { id: 'u-1', name: 'Ada Lovelace', count: 1200 },
                    { id: 'u-2', name: 'Grace', count: 8 },
                ],
            }),
        });

        const rows = find(host, 'analytics-contributors')?.querySelectorAll(
            'li',
        );

        expect(rows?.[0].textContent).toContain('AL');
        expect(rows?.[0].textContent).toContain('Ada Lovelace');
        expect(rows?.[0].textContent).toContain('1,200 msgs');
        expect(rows?.[1].textContent).toContain('G');
        expect(rows?.[1].textContent).toContain('8 msgs');
    });
});
