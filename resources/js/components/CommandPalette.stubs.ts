import type { Component } from 'vue';
import { defineComponent, h } from 'vue';

/**
 * The design-system stand-ins the palette's suites render it through: the
 * dialog shell, the command list, the filter field, the glyphs and the sanitized
 * body.
 *
 * They live outside the suites because the palette composes enough of the design
 * system that the preamble, repeated per suite, would dwarf the assertions. A
 * `vi.mock` factory is hoisted above the imports, so a suite reaches for what it
 * needs with a dynamic `import()` inside the factory.
 */

/**
 * Renders its tag with whatever it was handed, so a stubbed primitive still
 * carries the `data-test`, the classes and the ARIA the palette puts on it —
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
 * The glyphs, each rendering as an `<svg>` that keeps its classes. Every icon
 * the command registry names has to be here: a mocked module answers `undefined`
 * for anything it left out, and a row would then render nothing at all.
 */
export function lucideDouble(): Record<string, Component> {
    return Object.fromEntries(
        [
            'AlarmClock',
            'ArrowDown',
            'ArrowUp',
            'Bell',
            'BellOff',
            'BellRing',
            'Circle',
            'CircleDot',
            'Hash',
            'Keyboard',
            'Monitor',
            'Moon',
            'Plus',
            'Search',
            'SmilePlus',
            'SquarePen',
            'Sun',
            'UserPlus',
        ].map((icon) => [icon, passthrough('svg')]),
    );
}

/**
 * reka-ui's filter field, reduced to the input it renders. Unlike the inert
 * stubs it is bound both ways: the query it writes is what drives every ranked
 * group below it, so a suite types into it exactly as a viewer would.
 */
const filterField = defineComponent({
    name: 'ListboxFilterStub',
    inheritAttrs: false,
    props: { modelValue: { type: String, default: '' } },
    setup:
        (props, { attrs }) =>
        () =>
            h('input', {
                ...attrs,
                value: props.modelValue,
                onInput: (event: Event) =>
                    (attrs['onUpdate:modelValue'] as (value: string) => void)?.(
                        (event.target as HTMLInputElement).value,
                    ),
            }),
});

export function rekaDouble(): Record<string, Component> {
    return { ListboxFilter: filterField };
}

/**
 * A command row, which unlike the rest is not inert: the palette's whole job is
 * to turn a pick into a navigation, so the row reduces to the one gesture that
 * fires the handler bound to it. reka-ui announces that pick as `select`.
 */
const commandItem = defineComponent({
    name: 'CommandItemStub',
    inheritAttrs: false,
    setup:
        (_props, { attrs, slots }) =>
        () =>
            h(
                'button',
                {
                    ...attrs,
                    onClick: () =>
                        (attrs.onSelect as (event: Event) => void)?.(
                            new Event('select', { cancelable: true }),
                        ),
                },
                slots.default?.(),
            ),
});

/** A group, keeping its heading where a suite can read which one it is. */
const commandGroup = defineComponent({
    name: 'CommandGroupStub',
    inheritAttrs: false,
    props: { heading: { type: String, default: '' } },
    setup:
        (props, { attrs, slots }) =>
        () =>
            h(
                'section',
                { ...attrs, 'data-heading': props.heading },
                slots.default?.(),
            ),
});

/**
 * The list, marked the way the real one marks its listbox — which is how a suite
 * tells a row inside it from a line the palette renders below it.
 */
const commandList = defineComponent({
    name: 'CommandListStub',
    inheritAttrs: false,
    setup:
        (_props, { attrs, slots }) =>
        () =>
            h(
                'div',
                { ...attrs, 'data-slot': 'command-list' },
                slots.default?.(),
            ),
});

/** How reka-ui names the row the keyboard has landed on. */
type HighlightPayload = { value: unknown } | undefined;

/**
 * The listbox root's announcement of which row is highlighted, held where a
 * suite can fire it.
 *
 * Unlike the inert stubs this one has to be reachable: the highlight is not a
 * gesture a viewer makes on an element, it is reka-ui reporting where the
 * arrow keys have arrived, and it is the whole trigger for the palette's
 * prefetch. A suite settles a selection by calling this rather than by
 * simulating a keyboard the stubs do not have.
 */
export const highlighter = {
    settle: null as ((payload: HighlightPayload) => void) | null,
};

const commandRoot = defineComponent({
    name: 'CommandStub',
    inheritAttrs: false,
    setup(_props, { attrs, slots }) {
        highlighter.settle = attrs.onHighlight as (
            payload: HighlightPayload,
        ) => void;

        return () => h('div', attrs, slots.default?.());
    },
});

export function commandDouble(): Record<string, Component> {
    return {
        Command: commandRoot,
        CommandGroup: commandGroup,
        CommandItem: commandItem,
        CommandList: commandList,
    };
}

export function buttonDouble(): Record<string, Component> {
    return { Button: passthrough('button') };
}

export function presenceDotDouble(): Record<string, Component> {
    return { default: passthrough('span') };
}

export function dialogDouble(): Record<string, Component> {
    return {
        Dialog: passthrough('div'),
        DialogContent: passthrough('div'),
        DialogDescription: passthrough('p'),
        DialogHeader: passthrough('div'),
        DialogTitle: passthrough('h2'),
    };
}

/**
 * The sanitizing renderer, keeping the markup it was handed where a suite can
 * read it back — the palette's own contribution is choosing what to hand over.
 */
const safeHtml = defineComponent({
    name: 'SafeHtmlStub',
    inheritAttrs: false,
    props: { html: { type: String, default: '' } },
    setup:
        (props, { attrs }) =>
        () =>
            h('span', { ...attrs, 'data-html': props.html }),
});

export function safeHtmlDouble(): Record<string, Component> {
    return { default: safeHtml };
}
