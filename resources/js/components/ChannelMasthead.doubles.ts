import { vi } from 'vitest';
import type { App, Component, InjectionKey } from 'vue';
import { createApp, defineComponent, h, inject, provide, ref } from 'vue';
import { translate } from '@/lib/i18n';
import type { RenderedPresence } from '@/lib/presence';
import type { Channel, DmParticipant, Mention } from '@/types';

/**
 * The doubles the masthead's suites share: the shell primitives it renders
 * through, the composables that reach for the dock or the router, and the
 * mount/teardown boilerplate.
 *
 * They live outside the suites because the masthead pulls in enough of the
 * design system that the preamble, repeated per suite, would dwarf the
 * assertions. A `vi.mock` factory is hoisted above the imports, so a suite
 * reaches for what it needs with a dynamic `import()` inside the factory.
 */

/** The viewer, as `usePage()` reports them. Mutable so a suite can move them. */
export const viewer = {
    avatar: null as string | null,
    presence: 'active' as RenderedPresence,
};

/** The pieces of `@inertiajs/vue3` the masthead reads. */
export function inertiaDouble(): Record<string, unknown> {
    return {
        usePage: () => ({
            url: '/t/acme/c/general',
            props: { auth: { user: viewer } },
        }),
    };
}

/**
 * Renders its tag with whatever it was handed, so a stubbed primitive still
 * carries the `data-test`, the classes and the ARIA the masthead puts on it —
 * which is exactly what these suites assert on.
 */
export function passthrough(tag: string): Component {
    return defineComponent({
        name: `${tag}Passthrough`,
        inheritAttrs: false,
        setup:
            (_props, { attrs, slots }) =>
            () =>
                h(tag, attrs, slots.default?.()),
    });
}

/**
 * The design-system leaves the masthead composes, each reduced to the tag it
 * renders. They keep their own suites; what matters here is that the masthead's
 * attributes reach them, which `passthrough` preserves.
 */
export function avatarDouble(): Record<string, Component> {
    return {
        Avatar: passthrough('div'),
        AvatarImage: passthrough('img'),
        AvatarFallback: passthrough('span'),
    };
}

export function buttonDouble(): Record<string, Component> {
    return { Button: passthrough('button') };
}

export function tooltipDouble(): Record<string, Component> {
    return {
        Tooltip: passthrough('div'),
        TooltipTrigger: passthrough('div'),
        TooltipContent: passthrough('div'),
    };
}

/** The glyphs, each rendering as an `<svg>` that keeps its classes. */
export function lucideDouble(): Record<string, Component> {
    return Object.fromEntries(
        [
            'Archive',
            'Bot',
            'Check',
            'EllipsisVertical',
            'LogOut',
            'Pin',
            'Search',
            'Star',
            'UserPlus',
        ].map((icon) => [icon, passthrough('svg')]),
    );
}

type PickLevel = (value: string) => void;

/** How a stubbed radio item reaches the group's update handler. */
const PICK_LEVEL: InjectionKey<PickLevel | undefined> = Symbol('pick-level');

/**
 * The dropdown-menu primitives, which unlike the rest are not inert: the menu's
 * whole job is to turn a pick into an emit, so each item stub reduces to the
 * one gesture that fires the handler the masthead bound to it.
 */
export function dropdownMenuDouble(): Record<string, Component> {
    const item = defineComponent({
        name: 'DropdownMenuItemStub',
        inheritAttrs: false,
        setup:
            (_props, { attrs, slots }) =>
            () =>
                h(
                    'button',
                    {
                        ...attrs,
                        // reka-ui's item announces a pick as `select`, whose
                        // event the masthead sometimes calls `preventDefault()`
                        // on to keep the menu open.
                        onClick: () =>
                            (attrs.onSelect as (event: Event) => void)?.(
                                new Event('select', { cancelable: true }),
                            ),
                    },
                    slots.default?.(),
                ),
    });

    const checkboxItem = defineComponent({
        name: 'DropdownMenuCheckboxItemStub',
        inheritAttrs: false,
        props: { modelValue: { type: Boolean, default: false } },
        setup:
            (props, { attrs, slots }) =>
            () =>
                h(
                    'button',
                    {
                        ...attrs,
                        'aria-checked': String(props.modelValue),
                        onClick: () =>
                            (
                                attrs['onUpdate:modelValue'] as (
                                    value: boolean,
                                ) => void
                            )?.(!props.modelValue),
                    },
                    slots.default?.(),
                ),
    });

    /**
     * The radio group hands its update handler to its items, so picking a level
     * is one click on the item itself — the gesture reka-ui gives the viewer.
     */
    const radioGroup = defineComponent({
        name: 'DropdownMenuRadioGroupStub',
        inheritAttrs: false,
        props: { modelValue: { type: String, default: '' } },
        setup(props, { attrs, slots }) {
            provide(PICK_LEVEL, attrs['onUpdate:modelValue'] as PickLevel);

            return () =>
                h(
                    'div',
                    { ...attrs, 'data-value': props.modelValue },
                    slots.default?.(),
                );
        },
    });

    const radioItem = defineComponent({
        name: 'DropdownMenuRadioItemStub',
        inheritAttrs: false,
        props: { value: { type: String, default: '' } },
        setup(props, { attrs, slots }) {
            const pick = inject(PICK_LEVEL, undefined);

            return () =>
                h(
                    'button',
                    { ...attrs, onClick: () => pick?.(props.value) },
                    slots.default?.(),
                );
        },
    });

    return {
        DropdownMenu: passthrough('div'),
        DropdownMenuCheckboxItem: checkboxItem,
        DropdownMenuContent: passthrough('div'),
        DropdownMenuItem: item,
        DropdownMenuLabel: passthrough('div'),
        DropdownMenuRadioGroup: radioGroup,
        DropdownMenuRadioItem: radioItem,
        DropdownMenuSeparator: passthrough('hr'),
        DropdownMenuTrigger: passthrough('div'),
    };
}

/** The dock's own open state, which the search glyph expands before pinning. */
export const dock = {
    open: ref(true),
    setOpen: vi.fn((value: boolean) => {
        dock.open.value = value;
    }),
};

export function sidebarDouble(): Record<string, unknown> {
    return {
        SidebarTrigger: passthrough('button'),
        useSidebar: () => ({ open: dock.open, setOpen: dock.setOpen }),
    };
}

/** Where the search glyph sends the viewer, on each side of the breakpoint. */
export const navigation = {
    isMobile: ref(false),
    openDestination: vi.fn(),
    openQuickSwitcher: vi.fn(),
};

export function isMobileDouble(): Record<string, unknown> {
    return { useIsMobile: () => navigation.isMobile };
}

export function navPanelDouble(): Record<string, unknown> {
    return {
        useNavPanel: () => ({ openDestination: navigation.openDestination }),
    };
}

export function quickSwitcherDouble(): Record<string, unknown> {
    return {
        useQuickSwitcher: () => ({ open: navigation.openQuickSwitcher }),
    };
}

/** Reset every double's state between tests, so a suite reads in any order. */
export function resetDoubles(): void {
    viewer.avatar = null;
    viewer.presence = 'active';
    dock.open.value = true;
    dock.setOpen.mockClear();
    navigation.isMobile.value = false;
    navigation.openDestination.mockClear();
    navigation.openQuickSwitcher.mockClear();
}

export function channel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 'c1',
        name: 'general',
        slug: 'general',
        visibility: 'public',
        topic: null,
        isGeneral: true,
        isArchived: false,
        muted: false,
        notificationLevel: 'all',
        unreadCount: 0,
        mentionCount: 0,
        hasDraft: false,
        draft: null,
        starred: false,
        sectionId: null,
        position: 0,
        isDirect: false,
        isGroupDirect: false,
        dmUserId: null,
        dmParticipants: null,
        lastActivityAt: null,
        ...overrides,
    };
}

export function member(overrides: Partial<Mention> = {}): Mention {
    return { id: 'u1', name: 'Ada Lovelace', avatar: null, ...overrides };
}

/** The other side of a DM, as the channel payload carries them. */
export function participant(
    overrides: Partial<DmParticipant> = {},
): DmParticipant {
    return {
        id: 'peer',
        name: 'Ada Lovelace',
        avatar: null,
        isBot: false,
        status: null,
        presence: 'active',
        isDnd: false,
        ...overrides,
    };
}

/** Stands in for the notification indicator's Lucide glyph. */
export const notificationIcon = passthrough('svg');

const mounted: Array<{ app: App; host: HTMLElement }> = [];

/** Every event the masthead emits, in the order it emitted them. */
export type Emitted = Array<[string, unknown]>;

/**
 * Mount the masthead over its defaults, recording what it emits. The prop bag
 * is spread rather than made reactive: each case renders one variant of a
 * header that only ever re-renders from above.
 */
export function mountMasthead(
    Masthead: Component,
    props: Record<string, unknown> = {},
): { host: HTMLElement; emitted: Emitted } {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const emitted: Emitted = [];
    const record =
        (event: string) =>
        (payload?: unknown): void => {
            emitted.push([event, payload]);
        };

    const app = createApp({
        render: () =>
            h(Masthead, {
                channel: channel(),
                members: [],
                presenceFor: () => 'active' as RenderedPresence,
                isDndFor: () => false,
                title: 'general',
                canManagePreferences: false,
                canArchive: false,
                canLeave: false,
                canAddPeople: false,
                notificationLevels: [],
                starred: false,
                muted: false,
                pinCount: 0,
                notificationLevel: 'all',
                notificationStatus: null,
                onToggleStar: record('toggleStar'),
                onNotificationLevelChange: record('notificationLevelChange'),
                onMuteChange: record('muteChange'),
                onArchive: record('archive'),
                onLeave: record('leave'),
                onAddPeople: record('addPeople'),
                onOpenPins: record('openPins'),
                ...props,
            }),
    });

    app.config.globalProperties.$t = translate;
    app.mount(host);
    mounted.push({ app, host });

    return { host, emitted };
}

export function unmountAll(): void {
    for (const { app, host } of mounted.splice(0)) {
        app.unmount();
        host.remove();
    }
}

/** The element carrying a `data-test` selector, or null when it is absent. */
export function find(host: HTMLElement, dataTest: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${dataTest}"]`);
}

export function click(host: HTMLElement, selector: string): void {
    host.querySelector<HTMLElement>(selector)?.click();
}
