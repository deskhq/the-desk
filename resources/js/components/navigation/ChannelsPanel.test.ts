// @vitest-environment jsdom
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick, reactive } from 'vue';

const { patch, post } = vi.hoisted(() => ({ patch: vi.fn(), post: vi.fn() }));

const page = reactive<{ props: Record<string, unknown> }>({ props: {} });

vi.mock('@inertiajs/vue3', () => ({
    router: { patch, post, delete: vi.fn() },
    usePage: () => page,
}));

vi.mock('@/composables/useToast', () => {
    const toast = {
        error: vi.fn(),
        success: vi.fn(),
        warning: vi.fn(),
        progress: vi.fn(),
    };

    return { useToast: () => toast };
});

vi.mock('@lucide/vue', () => ({
    ChevronRight: { render: () => h('svg') },
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
                    (props.modelValue as unknown[]).map((element) =>
                        slots.item?.({ element }),
                    ),
                ),
    }),
}));

vi.mock('@/components/ChannelListItem.vue', () => ({
    default: defineComponent({
        props: { channel: { type: Object, required: true } },
        setup: (props) => () =>
            h('li', { 'data-test': `row-${props.channel.slug}` }),
    }),
}));

/** Stand-in for a group, reporting the channels it was handed. */
function groupStub(name: string) {
    return defineComponent({
        props: {
            channels: { type: Array, default: () => [] },
            section: { type: Object, default: null },
        },
        setup: (props) => () =>
            h('div', {
                'data-test': `${name}-${props.section?.id ?? 'group'}`,
                'data-channels': (props.channels as Channel[])
                    .map((entry) => entry.slug)
                    .join(','),
            }),
    });
}

vi.mock('@/components/navigation/CustomSectionGroup.vue', () => ({
    default: groupStub('custom'),
}));
vi.mock('@/components/navigation/DefaultChannelGroup.vue', () => ({
    default: groupStub('default'),
}));
vi.mock('@/components/navigation/DirectMessageGroup.vue', () => ({
    default: groupStub('direct'),
}));

import { useChannelSections } from '@/composables/useChannelSections';
import { useDialog } from '@/composables/useDialog';
import type { Channel, ChannelSection } from '@/types/channels';
import ChannelsPanel from './ChannelsPanel.vue';

function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 'c-1',
        name: 'general',
        slug: 'general',
        starred: false,
        sectionId: null,
        isDirect: false,
        lastActivityAt: null,
        ...overrides,
    } as Channel;
}

function section(overrides: Partial<ChannelSection> = {}): ChannelSection {
    return {
        id: 's-1',
        name: 'Projects',
        position: 0,
        collapsed: false,
        ...overrides,
    } as ChannelSection;
}

let app: App | null = null;

beforeEach(() => {
    patch.mockClear();
    post.mockClear();
    useDialog('switcher').isOpen.value = false;
    useChannelSections().cancelSectionForm();
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

function mountPanel(
    channels: Channel[] = [channel()],
    sections: ChannelSection[] = [],
) {
    page.props = {
        currentTeam: { slug: 'acme' },
        channel: { slug: 'general' },
        channels,
        channelSections: sections,
        collapsedChannelSections: [],
        auth: { user: { id: 'u-1', presence: 'active' } },
    };

    const host = document.createElement('div');
    document.body.append(host);

    app = createApp({
        render: () => h(ChannelsPanel, {}),
    });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    return { host };
}

/** Settle the tick and the frame the create field's focus is taken on. */
async function settleFocus(): Promise<void> {
    await nextTick();
    await new Promise((resolve) => requestAnimationFrame(resolve));
}

it('is the named navigation landmark the browser suite reaches the list by', () => {
    const { host } = mountPanel();
    const nav = host.querySelector('nav')!;

    expect(nav.getAttribute('data-test')).toBe('channels-nav');
    expect(nav.getAttribute('aria-label')).toBe('Channels');
});

it('opens the quick switcher from its pinned trigger', () => {
    const { host } = mountPanel();

    host.querySelector<HTMLElement>(
        '[data-test="quick-switcher-trigger"]',
    )!.click();

    expect(useDialog('switcher').isOpen.value).toBe(true);
});

it('hides the starred group until the viewer stars something', () => {
    const { host } = mountPanel();

    expect(
        host.querySelector('[data-test="section-content-starred"]'),
    ).toBeNull();
});

it('pins the starred channels above the rest, in their own drag group', () => {
    const { host } = mountPanel([channel({ starred: true })]);

    const starred = host.querySelector(
        '[data-test="section-content-starred"]',
    )!;

    expect(starred.querySelector('[data-test="row-general"]')).not.toBeNull();
    expect(
        starred
            .querySelector('[data-drag-group]')!
            .getAttribute('data-drag-group'),
    ).toBe('starred');
});

it('hands each group the channels partitioned into it', () => {
    const { host } = mountPanel(
        [
            channel({ id: 'c-1', slug: 'filed', sectionId: 's-1' }),
            channel({ id: 'c-2', slug: 'plain' }),
            channel({ id: 'c-3', slug: 'dm-bob', isDirect: true }),
        ],
        [section()],
    );

    expect(
        host
            .querySelector('[data-test="custom-s-1"]')!
            .getAttribute('data-channels'),
    ).toBe('filed');
    expect(
        host
            .querySelector('[data-test="default-group"]')!
            .getAttribute('data-channels'),
    ).toBe('plain');
    expect(
        host
            .querySelector('[data-test="direct-group"]')!
            .getAttribute('data-channels'),
    ).toBe('dm-bob');
});

it('reorders the custom sections in their own drag group', () => {
    const { host } = mountPanel(
        [],
        [section(), section({ id: 's-2', name: 'Ops', position: 1 })],
    );

    const sections = host.querySelector('[data-drag-group="sections"]')!;

    expect(
        [...sections.children].map((group) => group.getAttribute('data-test')),
    ).toEqual(['custom-s-1', 'custom-s-2']);
});

it('keeps the new-section field out of the list until it is asked for', () => {
    const { host } = mountPanel();

    expect(host.querySelector('[data-test="create-section-input"]')).toBeNull();
});

it('shows the new-section field at the foot of the list, and takes it', async () => {
    const { host } = mountPanel();

    useChannelSections().openSectionForm();
    await settleFocus();

    const field = host.querySelector<HTMLInputElement>(
        '[data-test="create-section-input"]',
    )!;

    expect(field).not.toBeNull();
    expect(document.activeElement).toBe(field);
});

it('re-takes the field when the menu is reopened over a showing form', async () => {
    const { host } = mountPanel();
    const sections = useChannelSections();

    sections.openSectionForm();
    await settleFocus();

    const field = host.querySelector<HTMLInputElement>(
        '[data-test="create-section-input"]',
    )!;
    field.blur();
    sections.openSectionForm();
    await settleFocus();

    expect(document.activeElement).toBe(field);
});

it('commits the new section on Enter by blurring the field', async () => {
    const { host } = mountPanel();
    const sections = useChannelSections();

    sections.openSectionForm();
    await settleFocus();

    const field = host.querySelector<HTMLInputElement>(
        '[data-test="create-section-input"]',
    )!;
    sections.newSectionName.value = 'Projects';
    field.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
    );
    await nextTick();

    expect(post.mock.calls[0][1]).toEqual({ name: 'Projects' });
});
