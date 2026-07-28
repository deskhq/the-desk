import type { Component, InjectionKey } from 'vue';
import { defineComponent, h, inject, provide } from 'vue';

/**
 * The design-system stand-ins the masthead's suites render it through: the
 * glyphs, the avatar and button primitives, the tooltip, and the menu.
 *
 * They live outside the suites because the masthead composes enough of the
 * design system that the preamble, repeated per suite, would dwarf the
 * assertions. A `vi.mock` factory is hoisted above the imports, so a suite
 * reaches for what it needs with a dynamic `import()` inside the factory.
 */

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
