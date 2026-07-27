// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';

/**
 * The pickers are reka-ui surfaces audited for real in the browser suite; here
 * they are flattened to their trigger and options so the row's own decision —
 * chip or picker, and what each control emits — is what is under test.
 */
const { passthrough } = await vi.hoisted(async () => {
    const { defineComponent: define, h: hyper } = await import('vue');

    return {
        passthrough: (tag: string) =>
            define({
                setup:
                    (
                        _: unknown,
                        ctx: {
                            slots: Record<string, (() => unknown) | undefined>;
                        },
                    ) =>
                    () =>
                        hyper(tag, [
                            ctx.slots.trigger?.(),
                            ctx.slots.default?.(),
                        ] as never),
            }),
    };
});

vi.mock('@/components/ui/combobox', () => ({
    Combobox: passthrough('div'),
    // The real listbox option answers a click with `select`; the stub keeps that
    // one behaviour, since choosing an option is what the row does with it.
    ComboboxItem: defineComponent({
        emits: ['select'],
        setup:
            (_, { slots, emit }) =>
            () =>
                h('div', { onClick: () => emit('select') }, slots.default?.()),
    }),
}));
vi.mock('@/components/ui/popover', () => ({
    Popover: passthrough('div'),
    PopoverContent: passthrough('div'),
    PopoverTrigger: passthrough('div'),
}));
vi.mock('@/components/ui/date-picker', () => ({
    DatePicker: defineComponent({
        setup: () => () => h('input'),
    }),
}));

import SearchFacetBar from './SearchFacetBar.vue';

const baseProps: {
    authorName: string | null;
    channelName: string | null;
    dateLabel: string | null;
    after: string | null;
    before: string | null;
    members: Array<{ id: string; name: string }>;
    channels: Array<{
        id: string;
        name: string;
        isPrivate: boolean;
        teamName: string | null;
    }>;
    hasFilters: boolean;
} = {
    authorName: null,
    channelName: null,
    dateLabel: null,
    after: null,
    before: null,
    members: [{ id: 'u-1', name: 'Carol Danvers' }],
    channels: [
        { id: 'c-1', name: 'general', isPrivate: false, teamName: null },
    ],
    hasFilters: false,
};

let app: App | null = null;
let root: HTMLDivElement;
const emitted: Array<[string, unknown[]]> = [];

function mount(props: Partial<typeof baseProps> = {}): void {
    root = document.createElement('div');
    document.body.append(root);
    emitted.length = 0;

    app = createApp(
        defineComponent({
            setup: () => () =>
                h(SearchFacetBar, {
                    ...baseProps,
                    ...props,
                    onAuthor: (...args: unknown[]) =>
                        emitted.push(['author', args]),
                    onChannel: (...args: unknown[]) =>
                        emitted.push(['channel', args]),
                    onRange: (...args: unknown[]) =>
                        emitted.push(['range', args]),
                    onClearAll: (...args: unknown[]) =>
                        emitted.push(['clearAll', args]),
                }),
        }),
    );
    app.config.globalProperties.$t = (
        key: string,
        replacements: Record<string, string | number> = {},
    ): string =>
        Object.entries(replacements).reduce(
            (out, [token, value]) => out.replaceAll(`:${token}`, String(value)),
            key,
        );
    app.mount(root);
}

function find(selector: string): HTMLElement | null {
    return root.querySelector<HTMLElement>(`[data-test="${selector}"]`);
}

afterEach(() => {
    app?.unmount();
    app = null;
    root.remove();
});

describe('SearchFacetBar', () => {
    it('offers a picker for every facet nobody has applied yet', () => {
        mount();

        expect(find('facet-author-picker')).not.toBeNull();
        expect(find('facet-channel-picker')).not.toBeNull();
        expect(find('facet-date-picker')).not.toBeNull();
        expect(find('facet-author')).toBeNull();
        expect(find('facet-clear-all')).toBeNull();
    });

    it('replaces a picker with its applied chip', () => {
        mount({ authorName: 'Carol Danvers', hasFilters: true });

        expect(find('facet-author')?.textContent).toContain('Carol Danvers');
        expect(find('facet-author-picker')).toBeNull();
        expect(find('facet-clear-all')).not.toBeNull();
    });

    it('names the channel and the date range on their own chips', () => {
        mount({
            channelName: 'general',
            dateLabel: 'Since 1 July',
            hasFilters: true,
        });

        expect(find('facet-channel')?.textContent).toContain('general');
        expect(find('facet-date')?.textContent).toContain('Since 1 July');
    });

    it('drops a facet from its chip', async () => {
        mount({ authorName: 'Carol Danvers', hasFilters: true });

        find('facet-author')?.querySelector('button')?.click();
        await nextTick();

        expect(emitted).toContainEqual(['author', [null]]);
    });

    it('clears the date range from the date chip', async () => {
        mount({ dateLabel: 'Since 1 July', hasFilters: true });

        find('facet-date')?.querySelector('button')?.click();
        await nextTick();

        expect(emitted).toContainEqual(['range', [null, null]]);
    });

    // The chip renders from the bounds, so a preset needs no identity of its own
    // beyond the range it sets.
    it('applies a date preset as a plain range', async () => {
        mount();

        find('facet-date-preset-today')?.click();
        await nextTick();

        const [name, args] = emitted[0] as [string, [string, string]];

        expect(name).toBe('range');
        expect(args[0]).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        expect(args[0]).toBe(args[1]);
    });

    it('opens on the custom range when one is already applied', () => {
        mount({ after: '2026-07-01', before: null, dateLabel: null });

        expect(find('facet-date-after')).not.toBeNull();
    });

    it('keeps the custom range folded away until it is asked for', async () => {
        mount();

        expect(find('facet-date-after')).toBeNull();

        find('facet-date-custom')?.click();
        await nextTick();

        expect(find('facet-date-after')).not.toBeNull();
    });

    it('picks a channel from the list it was given', async () => {
        mount();

        find('facet-channel-option')?.click();
        await nextTick();

        expect(emitted).toContainEqual(['channel', ['c-1']]);
    });
});
