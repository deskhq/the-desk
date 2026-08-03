// @vitest-environment jsdom
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, reactive } from 'vue';
import { useDialog } from '@/composables/useDialog';
import type { Channel } from '@/types/channels';

const page = reactive<{ props: Record<string, unknown> }>({
    props: { auth: { user: { id: 'u-1', presence: 'active' } } },
});

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

// The roster the rows read: u-2 is away and paused, everyone else offline.
vi.mock('@/composables/useTeamPresence', () => ({
    useTeamPresence: () => ({
        presenceFor: (userId: string) =>
            userId === 'u-2' ? 'away' : 'offline',
        isDndFor: (userId: string) => userId === 'u-2',
    }),
}));

vi.mock('@lucide/vue', () => ({
    ChevronRight: { render: () => h('svg') },
    Plus: { render: () => h('svg') },
}));

vi.mock('@/components/DirectMessageListItem.vue', () => ({
    default: defineComponent({
        props: {
            channel: { type: Object, required: true },
            presence: { type: String, default: '' },
            isDnd: { type: Boolean, default: false },
            isSelf: { type: Boolean, default: false },
        },
        setup: (props) => () =>
            h('li', {
                'data-test': `dm-row-${props.channel.slug}`,
                'data-presence': props.presence,
                'data-dnd': String(props.isDnd),
                'data-self': String(props.isSelf),
            }),
    }),
}));

import DirectMessageGroup from './DirectMessageGroup.vue';

function dm(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 'c-1',
        name: 'Bob',
        slug: 'dm-bob',
        isDirect: true,
        dmUserId: 'u-2',
        ...overrides,
    } as Channel;
}

let app: App | null = null;

beforeEach(() => {
    useDialog('newMessage').close();
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

function mountGroup(
    overrides: { channels?: Channel[]; collapsed?: boolean } = {},
) {
    const host = document.createElement('div');
    document.body.append(host);

    const toggle = vi.fn();

    app = createApp({
        render: () =>
            h(DirectMessageGroup, {
                channels: overrides.channels ?? [dm()],
                teamSlug: 'acme',
                activeChannelSlug: null,
                collapsed: overrides.collapsed ?? false,
                onToggle: toggle,
            }),
    });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    return { host, toggle };
}

it('keeps the selectors the browser suite reaches the group by', () => {
    const { host } = mountGroup();

    expect(
        host.querySelector('[data-test="direct-messages-group"]'),
    ).not.toBeNull();
    expect(
        host.querySelector('[data-test="section-toggle-direct"]'),
    ).not.toBeNull();
    expect(
        host.querySelector('[data-test="section-content-direct"]'),
    ).not.toBeNull();
    expect(host.querySelector('[data-test="new-dm-trigger"]')).not.toBeNull();
});

it('renders one row per conversation, in the order it was handed them', () => {
    const { host } = mountGroup({
        channels: [dm(), dm({ id: 'c-2', slug: 'dm-cara', dmUserId: 'u-3' })],
    });

    expect(
        [...host.querySelectorAll('li')].map((row) =>
            row.getAttribute('data-test'),
        ),
    ).toEqual(['dm-row-dm-bob', 'dm-row-dm-cara']);
});

it('draws each row with the presence and DND its participant reports', () => {
    const { host } = mountGroup();
    const row = host.querySelector('[data-test="dm-row-dm-bob"]')!;

    expect(row.getAttribute('data-presence')).toBe('away');
    expect(row.getAttribute('data-dnd')).toBe('true');
});

it("falls back to the viewer's own presence where there is no counterpart", () => {
    const { host } = mountGroup({
        channels: [dm({ slug: 'dm-group', dmUserId: null })],
    });
    const row = host.querySelector('[data-test="dm-row-dm-group"]')!;

    expect(row.getAttribute('data-presence')).toBe('active');
    expect(row.getAttribute('data-dnd')).toBe('false');
});

it('labels the note-to-self conversation as the viewer themselves', () => {
    const { host } = mountGroup({
        channels: [dm({ slug: 'dm-self', dmUserId: 'u-1' })],
    });

    expect(
        host
            .querySelector('[data-test="dm-row-dm-self"]')!
            .getAttribute('data-self'),
    ).toBe('true');
});

it('stands an empty state in until the first conversation exists', () => {
    const { host } = mountGroup({ channels: [] });

    expect(
        host.querySelector('[data-test="direct-messages-empty"]'),
    ).not.toBeNull();
});

it('keeps the collapsed list mounted, so its rows survive the toggle', () => {
    const { host } = mountGroup({ collapsed: true });
    const content = host.querySelector<HTMLElement>(
        '[data-test="section-content-direct"]',
    )!;

    expect(content.style.display).toBe('none');
    expect(content.querySelector('[data-test="dm-row-dm-bob"]')).not.toBeNull();
});

it('asks its host to collapse the group rather than doing it itself', () => {
    const { host, toggle } = mountGroup();

    host.querySelector<HTMLElement>(
        '[data-test="section-toggle-direct"]',
    )!.click();

    expect(toggle).toHaveBeenCalledTimes(1);
});

it('opens the people picker the shell mounts', () => {
    const { host } = mountGroup();

    host.querySelector<HTMLElement>('[data-test="new-dm-trigger"]')!.click();

    expect(useDialog('newMessage').isOpen.value).toBe(true);
});
