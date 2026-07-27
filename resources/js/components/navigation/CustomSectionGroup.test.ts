// @vitest-environment jsdom
import { afterEach, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick, ref } from 'vue';
import type { Channel, ChannelSection } from '@/types/channels';

vi.mock('@lucide/vue', () => ({
    ChevronRight: { render: () => h('svg') },
    GripVertical: { render: () => h('svg') },
    MoreVertical: { render: () => h('svg') },
    Pencil: { render: () => h('svg') },
    Trash2: { render: () => h('svg') },
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
        DropdownMenuItem: defineComponent({
            emits: ['select'],
            setup:
                (_, { slots, emit }) =>
                () =>
                    h(
                        'div',
                        { onClick: () => emit('select') },
                        slots.default?.(),
                    ),
        }),
    };
});

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
                    onClick: () => emit('move', null),
                }),
    }),
}));

import CustomSectionGroup from './CustomSectionGroup.vue';

function section(overrides: Partial<ChannelSection> = {}): ChannelSection {
    return {
        id: 's-1',
        name: 'Projects',
        position: 0,
        collapsed: false,
        ...overrides,
    } as ChannelSection;
}

function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 'c-1',
        name: 'general',
        slug: 'general',
        ...overrides,
    } as Channel;
}

let app: App | null = null;

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

function mountGroup(
    overrides: {
        section?: ChannelSection;
        channels?: Channel[];
        renaming?: boolean;
    } = {},
) {
    const host = document.createElement('div');
    document.body.append(host);

    const renaming = ref(overrides.renaming ?? false);
    const renameValue = ref('Projects');
    const emitted: Record<string, number> = {};
    const move = vi.fn();

    const count = (event: string) => () => {
        emitted[event] = (emitted[event] ?? 0) + 1;
    };

    app = createApp({
        render: () =>
            h(CustomSectionGroup, {
                section: overrides.section ?? section(),
                sections: [section()],
                teamSlug: 'acme',
                activeChannelSlug: null,
                renaming: renaming.value,
                channels: overrides.channels ?? [channel()],
                renameValue: renameValue.value,
                'onUpdate:renameValue': (value: string) => {
                    renameValue.value = value;
                },
                onToggle: count('toggle'),
                onRenameStart: count('renameStart'),
                onRenameCancel: count('renameCancel'),
                onRenameCommit: count('renameCommit'),
                onDelete: count('delete'),
                onMove: move,
            }),
    });
    app.config.globalProperties.$t = (
        key: string,
        replacements?: Record<string, unknown>,
    ) =>
        replacements
            ? Object.entries(replacements).reduce(
                  (line, [token, value]) =>
                      line.replaceAll(`:${token}`, String(value)),
                  key,
              )
            : key;
    app.mount(host);

    return { host, renaming, renameValue, emitted, move };
}

function click(host: HTMLElement, selector: string): void {
    host.querySelector<HTMLElement>(`[data-test="${selector}"]`)!.click();
}

it('keeps the section-scoped selectors the browser suite reaches it by', () => {
    const { host } = mountGroup();

    for (const selector of [
        'section-custom-s-1',
        'section-content-custom-s-1',
        'section-drag-s-1',
        'section-toggle-custom-s-1',
        'section-menu-s-1',
        'section-rename-s-1',
        'section-delete-s-1',
    ]) {
        expect(host.querySelector(`[data-test="${selector}"]`)).not.toBeNull();
    }
});

it('shares its drag group with the default list, so rows cross between them', () => {
    const { host } = mountGroup();

    expect(
        host
            .querySelector('[data-drag-group]')!
            .getAttribute('data-drag-group'),
    ).toBe('sidebar-channels');
});

it('carries the handle class the section drag is wired to', () => {
    const { host } = mountGroup();

    expect(
        host
            .querySelector('[data-test="section-drag-s-1"]')!
            .classList.contains('section-drag-handle'),
    ).toBe(true);
});

it('renders its rows as belonging to this section', () => {
    const { host } = mountGroup();

    expect(
        host
            .querySelector('[data-test="row-general"]')!
            .getAttribute('data-section'),
    ).toBe('s-1');
});

it('invites a drop while the section holds no channels', () => {
    const { host } = mountGroup({ channels: [] });

    expect(host.textContent).toContain('Drag channels here');
});

it('keeps the collapsed list mounted, so its rows survive the toggle', () => {
    const { host } = mountGroup({ section: section({ collapsed: true }) });
    const content = host.querySelector<HTMLElement>(
        '[data-test="section-content-custom-s-1"]',
    )!;

    expect(content.style.display).toBe('none');
    expect(content.querySelector('[data-test="row-general"]')).not.toBeNull();
});

it('names its expanded state for assistive tech', () => {
    const { host } = mountGroup();

    expect(
        host
            .querySelector('[data-test="section-toggle-custom-s-1"]')!
            .getAttribute('aria-expanded'),
    ).toBe('true');
});

it('asks its host to collapse, rename and delete rather than doing it itself', () => {
    const { host, emitted } = mountGroup();

    click(host, 'section-toggle-custom-s-1');
    click(host, 'section-rename-s-1');
    click(host, 'section-delete-s-1');

    expect(emitted).toEqual({ toggle: 1, renameStart: 1, delete: 1 });
});

it('starts a rename on a double-click of the name', () => {
    const { host, emitted } = mountGroup();

    host.querySelector('span.truncate')!.dispatchEvent(
        new MouseEvent('dblclick', { bubbles: true }),
    );

    expect(emitted.renameStart).toBe(1);
});

it('swaps the toggle for the editor while renaming, never nesting them', () => {
    const { host } = mountGroup({ renaming: true });

    expect(
        host.querySelector('[data-test="section-rename-input-s-1"]'),
    ).not.toBeNull();
    expect(
        host.querySelector('[data-test="section-toggle-custom-s-1"]'),
    ).toBeNull();
});

it('takes the editor with the name selected, off its own template ref', async () => {
    const { host, renaming } = mountGroup();

    renaming.value = true;
    await nextTick();
    await nextTick();

    const input = host.querySelector<HTMLInputElement>(
        '[data-test="section-rename-input-s-1"]',
    )!;

    expect(document.activeElement).toBe(input);
    expect(input.selectionStart).toBe(0);
    expect(input.selectionEnd).toBe('Projects'.length);
});

it('commits the editor on Enter by blurring it', async () => {
    const { host, emitted } = mountGroup({ renaming: true });

    const input = host.querySelector<HTMLInputElement>(
        '[data-test="section-rename-input-s-1"]',
    )!;
    input.focus();
    input.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
    );
    await nextTick();

    expect(emitted.renameCommit).toBe(1);
});

it('abandons the editor on Escape', async () => {
    const { host, emitted } = mountGroup({ renaming: true });

    host.querySelector('[data-test="section-rename-input-s-1"]')!.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    );
    await nextTick();

    expect(emitted.renameCancel).toBe(1);
});

it('passes a row move up with the channel it belongs to', () => {
    const { host, move } = mountGroup();

    click(host, 'row-general');

    expect(move).toHaveBeenCalledWith(
        expect.objectContaining({ slug: 'general' }),
        null,
    );
});
