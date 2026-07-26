// @vitest-environment jsdom
import { afterEach, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import type { NavDestination } from '@/composables/useNavPanel';
import type { User } from '@/types';

vi.mock('@lucide/vue', () => ({
    AlarmClock: { render: () => h('svg') },
    Hash: { render: () => h('svg') },
    MessagesSquare: { render: () => h('svg') },
    Search: { render: () => h('svg') },
}));

vi.mock('@/components/ui/avatar', () => ({
    Avatar: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('span', slots.default?.()),
    }),
    AvatarImage: defineComponent({ setup: () => () => h('img') }),
    AvatarFallback: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('span', slots.default?.()),
    }),
}));

import NavigationTabBar from './NavigationTabBar.vue';

const viewer: User = {
    id: 'u-1',
    name: 'Dana Ubuntu',
    email: 'dana@example.test',
    avatar: null,
} as unknown as User;

let app: App | null = null;

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

function mountTabBar(
    overrides: { active?: NavDestination; hasUnreadThreads?: boolean } = {},
) {
    const host = document.createElement('div');
    document.body.append(host);

    const select = vi.fn();

    app = createApp({
        render: () =>
            h(NavigationTabBar, {
                active: overrides.active ?? 'channels',
                user: viewer,
                presence: 'active',
                isDnd: false,
                hasUnreadThreads: overrides.hasUnreadThreads ?? false,
                onSelect: select,
            }),
    });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    return { host, select };
}

function tab(host: HTMLElement, destination: NavDestination): HTMLElement {
    const button = host.querySelector<HTMLElement>(
        `[data-test="tab-destination-${destination}"]`,
    );

    expect(button).not.toBeNull();

    return button!;
}

it('is a named navigation landmark', () => {
    const { host } = mountTabBar();

    const bar = host.querySelector('nav[data-test="navigation-tab-bar"]');

    expect(bar).not.toBeNull();
    expect(bar!.getAttribute('aria-label')).toBe('Destinations');
});

it('lays out the five destinations with visible labels', () => {
    const { host } = mountTabBar();

    for (const [destination, label] of [
        ['channels', 'Channels'],
        ['threads', 'Threads'],
        ['reminders', 'Reminders'],
        ['search', 'Search'],
        ['you', 'You'],
    ] as const) {
        expect(tab(host, destination).textContent).toContain(label);
    }
});

it('keeps every tap target at the 44px floor', () => {
    const { host } = mountTabBar();

    for (const destination of [
        'channels',
        'threads',
        'reminders',
        'search',
        'you',
    ] as const) {
        expect(tab(host, destination).className).toContain('min-h-11');
    }
});

it('marks only the open destination with aria-current', () => {
    const { host } = mountTabBar({ active: 'search' });

    expect(tab(host, 'search').getAttribute('aria-current')).toBe('true');
    expect(tab(host, 'you').getAttribute('aria-current')).toBeNull();
});

it('asks for a destination when its tab is activated', () => {
    const { host, select } = mountTabBar();

    tab(host, 'reminders').click();

    expect(select).toHaveBeenCalledWith('reminders');
});

it('flags unread threads on the threads tab only while there are any', () => {
    const unread = mountTabBar({ hasUnreadThreads: true });

    expect(
        unread.host.querySelector('[data-test="tab-threads-unread-dot"]'),
    ).not.toBeNull();

    app?.unmount();
    app = null;
    document.body.innerHTML = '';

    const read = mountTabBar();

    expect(
        read.host.querySelector('[data-test="tab-threads-unread-dot"]'),
    ).toBeNull();
});

it('reserves the safe-area inset below the last row of tabs', () => {
    const { host } = mountTabBar();

    const bar = host.querySelector<HTMLElement>(
        '[data-test="navigation-tab-bar"]',
    );

    expect(bar!.style.paddingBottom).toContain('env(safe-area-inset-bottom)');
});
