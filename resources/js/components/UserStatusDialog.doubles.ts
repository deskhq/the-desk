import { CalendarDate } from '@internationalized/date';
import type { App as VueApp, Component, InjectionKey } from 'vue';
import {
    createApp,
    defineComponent,
    h,
    inject,
    nextTick,
    provide,
    reactive,
    ref,
} from 'vue';
import { translate } from '@/lib/i18n';

/**
 * The stand-ins the status dialog's two suites render it through, and the
 * recorders they read afterwards.
 *
 * They live outside the suites because the dialog composes enough of the design
 * system — the overlay, three selects, the calendar, the emoji picker — that the
 * preamble, repeated per suite, would dwarf the assertions. A `vi.mock` factory
 * is hoisted above the imports, so a suite reaches for what it needs with a
 * dynamic `import()` inside the factory.
 */

/** A request the dialog fired at the status endpoints. */
export type RecordedRequest = {
    url: string;
    payload: Record<string, unknown>;
    options: {
        preserveScroll?: boolean;
        onSuccess?: () => void;
        onError?: () => void;
        onFinish?: () => void;
    };
};

export const requests = reactive({
    puts: [] as RecordedRequest[],
    deletes: [] as RecordedRequest[],
});

/** The error copy the dialog raised, in the order it raised it. */
export const toasted = reactive({ errors: [] as string[] });

/** The signed-in user the dialog reads its current status and zone from. */
export const viewer = reactive({
    name: 'Ada Lovelace',
    timezone: 'UTC' as string | null,
    status: null as App.Data.UserStatusData | null,
});

export function resetDoubles(): void {
    requests.puts = [];
    requests.deletes = [];
    toasted.errors = [];
    viewer.name = 'Ada Lovelace';
    viewer.timezone = 'UTC';
    viewer.status = null;
}

/**
 * Renders its tag with whatever it was handed, so a stubbed primitive still
 * carries the `data-test`, the classes and the ARIA the dialog puts on it —
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

export function inertiaDouble(): Record<string, unknown> {
    return {
        usePage: () => ({ props: { auth: { user: viewer } } }),
        router: {
            put: (
                url: string,
                payload: Record<string, unknown>,
                options: RecordedRequest['options'],
            ) => requests.puts.push({ url, payload, options }),
            delete: (url: string, options: RecordedRequest['options']) =>
                requests.deletes.push({ url, payload: {}, options }),
        },
    };
}

export function toastDouble(): Record<string, unknown> {
    return {
        useToast: () => ({
            error: (message: string) => toasted.errors.push(message),
        }),
    };
}

/** Renders its content only while open, the way the real overlay does. */
export function dialogDouble(): Record<string, Component> {
    return {
        Dialog: defineComponent({
            name: 'DialogStub',
            props: { open: { type: Boolean, default: false } },
            emits: ['update:open'],
            setup:
                (props, { slots }) =>
                () =>
                    props.open
                        ? h('div', { 'data-stub': 'dialog' }, slots.default?.())
                        : null,
        }),
        DialogContent: passthrough('div'),
        DialogDescription: passthrough('p'),
        DialogHeader: passthrough('div'),
        DialogTitle: passthrough('h2'),
    };
}

export function inputDouble(): Record<string, Component> {
    return {
        Input: defineComponent({
            name: 'InputStub',
            inheritAttrs: false,
            props: { modelValue: { type: String, default: '' } },
            emits: ['update:modelValue'],
            setup:
                (props, { attrs, emit }) =>
                () =>
                    h('input', {
                        ...attrs,
                        value: props.modelValue,
                        onInput: (event: Event) =>
                            emit(
                                'update:modelValue',
                                (event.target as HTMLInputElement).value,
                            ),
                    }),
        }),
    };
}

const chooseKey = Symbol('select-stub') as InjectionKey<
    (value: unknown) => void
>;

/**
 * The select, reduced to its options: each item renders as a button that sets
 * the model, so a suite picks one by clicking it rather than by driving the
 * real listbox's pointer and keyboard model.
 */
export function selectDouble(): Record<string, Component> {
    return {
        Select: defineComponent({
            name: 'SelectStub',
            props: { modelValue: { type: null, default: null } },
            emits: ['update:modelValue'],
            setup(props, { emit, slots }) {
                provide(chooseKey, (value) => emit('update:modelValue', value));

                return () =>
                    h(
                        'div',
                        {
                            'data-stub': 'select',
                            'data-value': String(props.modelValue),
                        },
                        slots.default?.(),
                    );
            },
        }),
        SelectContent: passthrough('div'),
        SelectTrigger: passthrough('button'),
        SelectValue: passthrough('span'),
        SelectItem: defineComponent({
            name: 'SelectItemStub',
            props: { value: { type: null, required: true } },
            setup(props, { slots }) {
                const choose = inject(chooseKey, null);

                return () =>
                    h(
                        'button',
                        {
                            'data-stub': 'select-item',
                            'data-value': String(props.value),
                            onClick: () => choose?.(props.value),
                        },
                        slots.default?.(),
                    );
            },
        }),
    };
}

/**
 * The calendar, reduced to a text field taking an ISO date, so a suite names the
 * day it wants instead of walking a month grid.
 */
export function calendarDouble(): Record<string, Component> {
    return {
        Calendar: defineComponent({
            name: 'CalendarStub',
            props: {
                modelValue: { type: null, default: undefined },
                minValue: { type: null, default: undefined },
            },
            emits: ['update:modelValue'],
            setup(props, { emit }) {
                return () =>
                    h('input', {
                        'data-stub': 'calendar',
                        'data-min': props.minValue
                            ? String(props.minValue)
                            : '',
                        value: props.modelValue ? String(props.modelValue) : '',
                        onInput: (event: Event) => {
                            const [year, month, day] = (
                                event.target as HTMLInputElement
                            ).value
                                .split('-')
                                .map(Number);

                            emit(
                                'update:modelValue',
                                new CalendarDate(year, month, day),
                            );
                        },
                    });
            },
        }),
    };
}

/** The emoji the picker double hands back when its trigger is clicked. */
export const PICKED_EMOJI = '🎉';

export function emojiPickerDouble(): Record<string, Component> {
    return {
        default: defineComponent({
            name: 'EmojiPickerPopoverStub',
            inheritAttrs: false,
            emits: ['select'],
            setup:
                (_props, { emit, slots }) =>
                () =>
                    h('div', { 'data-stub': 'emoji-picker' }, [
                        slots.default?.(),
                        h('button', {
                            'data-test': 'stub-pick-emoji',
                            onClick: () => emit('select', PICKED_EMOJI),
                        }),
                    ]),
        }),
    };
}

export function statusEmojiDouble(): Record<string, Component> {
    return {
        default: defineComponent({
            name: 'UserStatusEmojiStub',
            props: {
                status: { type: null, default: null },
                name: { type: String, default: '' },
                decorative: { type: Boolean, default: false },
            },
            setup: (props) => () =>
                h(
                    'span',
                    { 'data-test': 'user-status-emoji' },
                    (props.status as App.Data.UserStatusData | null)?.emoji ??
                        '',
                ),
        }),
    };
}

export function lucideDouble(): Record<string, Component> {
    return Object.fromEntries(
        ['Clock', 'Smile'].map((name) => [name, passthrough('svg')]),
    );
}

const mounted: Array<{ app: VueApp; host: HTMLElement }> = [];

/** Every `update:open` the dialog emitted, in order. */
export type OpenEvents = boolean[];

/**
 * Mounts the dialog closed, the way the layout holds it, and hands back the
 * switch that opens it — the dialog only seeds its form on the way open, so a
 * suite that mounted it already open would be reading an unseeded draft.
 */
export function mountDialog(Dialog: Component): {
    host: HTMLElement;
    open: (isOpen?: boolean) => Promise<void>;
    emitted: OpenEvents;
} {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const isOpen = ref(false);
    const emitted: OpenEvents = [];

    const app = createApp({
        render: () =>
            h(Dialog, {
                open: isOpen.value,
                'onUpdate:open': (value: boolean) => {
                    isOpen.value = value;
                    emitted.push(value);
                },
            }),
    });

    app.config.globalProperties.$t = translate;
    app.mount(host);
    mounted.push({ app, host });

    return {
        host,
        open: async (value = true) => {
            isOpen.value = value;
            await nextTick();
            await nextTick();
        },
        emitted,
    };
}

export function unmountAll(): void {
    for (const { app, host } of mounted.splice(0)) {
        app.unmount();
        host.remove();
    }
}

/** The element carrying a `data-test` selector, or null when it is absent. */
export function find(host: HTMLElement, selector: string): HTMLElement | null {
    return host.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}

export function findAll(host: HTMLElement, selector: string): HTMLElement[] {
    return [...host.querySelectorAll<HTMLElement>(`[data-test="${selector}"]`)];
}

/** Whether a control is currently holding out, as its element reports it. */
export function isDisabled(element: Element | null): boolean {
    return (element as HTMLButtonElement | null)?.disabled === true;
}

export async function click(element: Element | null): Promise<void> {
    (element as HTMLElement | null)?.click();
    await nextTick();
}

/** Types into a text field the way a person would, model binding included. */
export async function type(
    element: Element | null,
    value: string,
): Promise<void> {
    const field = element as HTMLInputElement | null;

    if (field) {
        field.value = value;
        field.dispatchEvent(new Event('input'));
    }

    await nextTick();
}

/** What the select the given trigger belongs to currently holds. */
export function selectValue(host: HTMLElement, trigger: string): string | null {
    return (
        find(host, trigger)
            ?.closest('[data-stub="select"]')
            ?.getAttribute('data-value') ?? null
    );
}

/** The values the select the given trigger belongs to offers, in order. */
export function selectOptions(host: HTMLElement, trigger: string): string[] {
    const select = find(host, trigger)?.closest('[data-stub="select"]');

    return [
        ...(select?.querySelectorAll<HTMLElement>(
            '[data-stub="select-item"]',
        ) ?? []),
    ].map((item) => item.dataset.value ?? '');
}

/**
 * Picks an option out of the select the given trigger belongs to, named by the
 * value the real `SelectItem` carries.
 */
export async function choose(
    host: HTMLElement,
    trigger: string,
    value: string | number,
): Promise<void> {
    const select = find(host, trigger)?.closest('[data-stub="select"]');

    await click(select?.querySelector(`[data-value="${value}"]`) ?? null);
}
