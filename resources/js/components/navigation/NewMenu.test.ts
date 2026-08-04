// @vitest-environment jsdom
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h } from 'vue';
import { useDialog } from '@/composables/useDialog';

/** The workspace props the "New channel" row is gated on. */
const page = vi.hoisted(() => ({
    props: { creatableChannelVisibilities: ['public', 'private'] } as Record<
        string,
        unknown
    >,
}));

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

/** Drives the mocked breakpoint flag, which has to stay a real ref. */
const viewport = vi.hoisted(() => ({
    setMobile: null as null | ((mobile: boolean) => void),
}));

vi.mock('@/composables/useIsMobile', async () => {
    const { ref } = await import('vue');
    const isMobile = ref(false);

    viewport.setMobile = (mobile: boolean) => {
        isMobile.value = mobile;
    };

    return { useIsMobile: () => isMobile };
});

vi.mock('@lucide/vue', () => ({
    FolderPlus: { render: () => h('svg') },
    Hash: { render: () => h('svg') },
    MessageSquare: { render: () => h('svg') },
}));

/** Lets a test replay the moment the menu finishes closing. */
const menu = vi.hoisted(() => ({ replayClose: null as null | (() => Event) }));

/** Renders its slot and forwards a click as the `select` menu rows are wired to. */
function passthrough(tag = 'div') {
    return defineComponent({
        inheritAttrs: false,
        emits: ['select', 'update:open'],
        setup:
            (_, { slots, emit, attrs }) =>
            () =>
                h(
                    tag,
                    { ...attrs, onClick: () => emit('select', new Event('x')) },
                    slots.default?.(),
                ),
    });
}

/**
 * The menu body, which additionally exposes its `close-auto-focus` — the moment
 * the real menu is done with focus, and the one the "New section" row is
 * announced from. `replayClose` lets a test drive that moment.
 */
function menuContent() {
    return defineComponent({
        inheritAttrs: false,
        emits: ['closeAutoFocus'],
        setup(_, { slots, emit, attrs }) {
            menu.replayClose = () => {
                const event = new Event('closeautofocus', { cancelable: true });
                emit('closeAutoFocus', event);

                return event;
            };

            return () => h('div', { ...attrs }, slots.default?.());
        },
    });
}

vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: passthrough(),
    DropdownMenuTrigger: passthrough(),
    DropdownMenuContent: menuContent(),
    DropdownMenuItem: passthrough('button'),
}));

vi.mock('@/components/ui/dialog', () => ({
    Dialog: passthrough(),
    DialogTrigger: passthrough(),
    DialogContent: passthrough(),
    DialogTitle: passthrough(),
    DialogDescription: passthrough(),
}));

import NewMenu from './NewMenu.vue';

let app: App | null = null;

beforeEach(() => {
    viewport.setMobile?.(false);
    page.props = { creatableChannelVisibilities: ['public', 'private'] };
    menu.replayClose = null;
    useDialog('newMessage').close();
    useDialog('createChannel').close();
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

function mountMenu() {
    const host = document.createElement('div');
    document.body.append(host);

    const sectioned = vi.fn();

    app = createApp({
        render: () =>
            h(
                NewMenu,
                { onSection: sectioned },
                {
                    default: () =>
                        h('button', { 'data-test': 'new-menu-trigger' }),
                },
            ),
    });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    return { host, sectioned };
}

function row(host: HTMLElement, name: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${name}"]`);
}

it('offers exactly three ways to start something', () => {
    const { host } = mountMenu();

    expect(row(host, 'new-menu-channel')).not.toBeNull();
    expect(row(host, 'new-menu-message')).not.toBeNull();
    expect(row(host, 'create-section-trigger')).not.toBeNull();
    // And nothing else: the menu is a fixed three, not a drawer for whatever
    // else needs a home.
    expect(
        host.querySelectorAll('[data-test="new-menu"] [data-test]'),
    ).toHaveLength(3);
});

it('never carries an invite row — membership is the workspace sheet job', () => {
    const { host } = mountMenu();

    expect(row(host, 'new-menu')!.textContent).not.toContain('Invite');
    expect(row(host, 'invite-member-trigger')).toBeNull();
});

it('keeps the trigger the caller supplied', () => {
    const { host } = mountMenu();

    expect(row(host, 'new-menu-trigger')).not.toBeNull();
});

it('opens the create form the shell mounts, closing the menu behind it', () => {
    const { host } = mountMenu();

    row(host, 'new-menu-channel')!.click();

    expect(useDialog('createChannel').isOpen.value).toBe(true);
});

it('drops the channel row for a viewer the workspace policy shuts out', () => {
    // The row used to disappear because the modal wrapping it declined to
    // render; unpicked from that wrapper, the menu owes the gate itself.
    page.props = { creatableChannelVisibilities: [] };

    const { host } = mountMenu();

    expect(row(host, 'new-menu-channel')).toBeNull();
    expect(row(host, 'new-menu-message')).not.toBeNull();
});

it('opens the people picker the shell mounts', () => {
    const { host } = mountMenu();

    row(host, 'new-menu-message')!.click();

    expect(useDialog('newMessage').isOpen.value).toBe(true);
});

it('hands the new section over once the menu is done with focus', () => {
    const { host, sectioned } = mountMenu();

    row(host, 'create-section-trigger')!.click();

    // Announcing on `select` would race the menu's own teardown for the caret,
    // so the row waits for the close.
    expect(sectioned).not.toHaveBeenCalled();

    const event = menu.replayClose!();

    expect(sectioned).toHaveBeenCalledTimes(1);
    // And the menu gives up its restore, since focus is going to the field.
    expect(event.defaultPrevented).toBe(true);
});

it('leaves the focus restore alone when the menu closes on anything else', () => {
    const { host, sectioned } = mountMenu();

    row(host, 'new-menu-message')!.click();

    const event = menu.replayClose!();

    expect(sectioned).not.toHaveBeenCalled();
    expect(event.defaultPrevented).toBe(false);
});

it('presents the same three rows as a bottom sheet below the breakpoint', () => {
    viewport.setMobile?.(true);

    const { host, sectioned } = mountMenu();

    expect(row(host, 'new-menu-trigger')).not.toBeNull();
    expect(row(host, 'new-menu-channel')).not.toBeNull();

    row(host, 'new-menu-message')!.click();
    row(host, 'create-section-trigger')!.click();

    expect(useDialog('newMessage').isOpen.value).toBe(true);
    expect(sectioned).toHaveBeenCalledTimes(1);
});
