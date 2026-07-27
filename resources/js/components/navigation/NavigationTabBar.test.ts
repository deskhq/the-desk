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
    AvatarImage: defineComponent({
        props: { src: { type: String, default: '' } },
        setup: (props) => () => h('img', { src: props.src }),
    }),
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
    overrides: {
        active?: NavDestination;
        hasUnreadThreads?: boolean;
        hasPendingReminders?: boolean;
        avatar?: string;
    } = {},
) {
    const host = document.createElement('div');
    document.body.append(host);

    const select = vi.fn();

    app = createApp({
        render: () =>
            h(NavigationTabBar, {
                active: overrides.active ?? 'channels',
                user: { ...viewer, avatar: overrides.avatar },
                presence: 'active',
                isDnd: false,
                hasUnreadThreads: overrides.hasUnreadThreads ?? false,
                hasPendingReminders: overrides.hasPendingReminders ?? false,
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

it('flags pending reminders on the reminders tab only while there are any', () => {
    const pending = mountTabBar({ hasPendingReminders: true });

    const dot = pending.host.querySelector(
        '[data-test="tab-reminders-pending-dot"]',
    );

    expect(dot).not.toBeNull();
    expect(dot?.getAttribute('aria-hidden')).toBe('true');
    expect(tab(pending.host, 'reminders').textContent).toContain(
        'Reminders pending',
    );

    app?.unmount();
    app = null;
    document.body.innerHTML = '';

    const clear = mountTabBar();

    expect(
        clear.host.querySelector('[data-test="tab-reminders-pending-dot"]'),
    ).toBeNull();
    expect(tab(clear.host, 'reminders').textContent).not.toContain(
        'Reminders pending',
    );
});

it('reserves the safe-area inset below the last row of tabs', () => {
    const { host } = mountTabBar();

    const bar = host.querySelector<HTMLElement>(
        '[data-test="navigation-tab-bar"]',
    );

    expect(bar!.style.paddingBottom).toContain('env(safe-area-inset-bottom)');
});

it('draws an uploaded avatar in place of the initials fallback', () => {
    const { host } = mountTabBar({ avatar: 'https://cdn.test/dana.png' });

    const image = tab(host, 'you').querySelector('img');

    expect(image).not.toBeNull();
    expect(image!.getAttribute('src')).toBe('https://cdn.test/dana.png');
});

it('falls back to the viewer initials when there is no avatar', () => {
    const { host } = mountTabBar();

    const you = tab(host, 'you');

    expect(you.querySelector('img')).toBeNull();
    expect(you.textContent).toContain('DU');
});
