// @vitest-environment jsdom
import { afterEach, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import type { Channel, ChannelSection } from '@/types/channels';

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
}));

vi.mock('@lucide/vue', () => ({
    ChevronRight: { render: () => h('svg') },
    MoreHorizontal: { render: () => h('svg') },
    Plus: { render: () => h('svg') },
    Search: { render: () => h('svg') },
}));

vi.mock('vuedraggable', () => ({
    default: defineComponent({
        props: {
            modelValue: { type: Array, default: () => [] },
            group: { type: Object, default: () => ({}) },
            tag: { type: String, default: 'div' },
        },
        setup:
            (props, { slots }) =>
            () =>
                h(
                    props.tag,
                    { 'data-drag-group': props.group.name },
                    (props.modelValue as Channel[]).map((element) =>
                        slots.item?.({ element }),
                    ),
                ),
    }),
}));

/**
 * reka-ui only mounts a menu's content once it is open, and through a portal.
 * The rows are what this component owes its host, so the menu is flattened to
 * render them inline; that the real menu opens is the browser suite's business.
 */
vi.mock('@/components/ui/dropdown-menu', () => {
    const wrapper = defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('div', slots.default?.()),
    });

    return {
        DropdownMenu: wrapper,
        DropdownMenuTrigger: wrapper,
        DropdownMenuContent: wrapper,
        DropdownMenuItem: wrapper,
    };
});

vi.mock('@/components/CreateChannelModal.vue', () => ({
    default: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
}));

vi.mock('@/components/ChannelListItem.vue', () => ({
    default: defineComponent({
        props: {
            channel: { type: Object, required: true },
            currentSectionId: { type: String, default: null },
        },
        emits: ['move'],
        setup:
            (props, { emit }) =>
            () =>
                h('li', {
                    'data-test': `row-${props.channel.slug}`,
                    'data-section': String(props.currentSectionId),
                    onClick: () => emit('move', 's-9'),
                }),
    }),
}));

import DefaultChannelGroup from './DefaultChannelGroup.vue';

function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 'c-1',
        name: 'general',
        slug: 'general',
        ...overrides,
    } as Channel;
}

const sections = [{ id: 's-1', name: 'Projects' } as ChannelSection];

let app: App | null = null;

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

function mountGroup(
    overrides: {
        channels?: Channel[];
        collapsed?: boolean;
        teamSlug?: string;
    } = {},
) {
    const host = document.createElement('div');
    document.body.append(host);

    const move = vi.fn();
    const toggle = vi.fn();

    app = createApp({
        render: () =>
            h(DefaultChannelGroup, {
                channels: overrides.channels ?? [channel()],
                sections,
                teamSlug: overrides.teamSlug ?? 'acme',
                activeChannelSlug: null,
                collapsed: overrides.collapsed ?? false,
                onMove: move,
                onToggle: toggle,
            }),
    });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    return { host, move, toggle };
}

it('keeps the selectors the browser suite reaches the group by', () => {
    const { host } = mountGroup();

    expect(
        host.querySelector('[data-test="section-toggle-channels"]'),
    ).not.toBeNull();
    expect(
        host.querySelector('[data-test="section-content-channels"]'),
    ).not.toBeNull();
    expect(
        host.querySelector('[data-test="channels-section-menu"]'),
    ).not.toBeNull();
    expect(
        host.querySelector('[data-test="create-channel-trigger"]'),
    ).not.toBeNull();
});

it('keeps the tour anchor resolvable on the create-channel trigger', () => {
    const { host } = mountGroup();

    expect(host.querySelector('[data-tour="create-channel"]')).not.toBeNull();
});

it('shares its drag group with the custom sections, so rows cross between them', () => {
    const { host } = mountGroup();

    expect(
        host
            .querySelector('[data-drag-group]')!
            .getAttribute('data-drag-group'),
    ).toBe('sidebar-channels');
});

it('links out to the channel browser', () => {
    const { host } = mountGroup();

    expect(
        host
            .querySelector<HTMLAnchorElement>('[data-test="browse-channels"]')!
            .getAttribute('href'),
    ).toContain('/t/acme/');
});

it('withholds the team-scoped actions until a workspace is selected', () => {
    const { host } = mountGroup({ teamSlug: '' });

    expect(
        host.querySelector('[data-test="channels-section-menu"]'),
    ).toBeNull();
    expect(
        host.querySelector('[data-test="create-channel-trigger"]'),
    ).toBeNull();
});

it('renders rows as belonging to no section', () => {
    const { host } = mountGroup();

    expect(
        host
            .querySelector('[data-test="row-general"]')!
            .getAttribute('data-section'),
    ).toBe('null');
});

it('stands a dashed hint in until the first channel appears', () => {
    const { host } = mountGroup({ channels: [] });

    expect(
        host.querySelector('[data-test="no-channels-empty"]'),
    ).not.toBeNull();
});

it('keeps the collapsed list mounted, so its rows survive the toggle', () => {
    const { host } = mountGroup({ collapsed: true });
    const content = host.querySelector<HTMLElement>(
        '[data-test="section-content-channels"]',
    )!;

    expect(content.style.display).toBe('none');
    expect(content.querySelector('[data-test="row-general"]')).not.toBeNull();
});

it('passes a row move up with the channel it belongs to', () => {
    const { host, move } = mountGroup();

    host.querySelector<HTMLElement>('[data-test="row-general"]')!.click();

    expect(move).toHaveBeenCalledWith(
        expect.objectContaining({ slug: 'general' }),
        's-9',
    );
});

it('asks its host to collapse the group rather than doing it itself', () => {
    const { host, toggle } = mountGroup();

    host.querySelector<HTMLElement>(
        '[data-test="section-toggle-channels"]',
    )!.click();

    expect(toggle).toHaveBeenCalledTimes(1);
});
