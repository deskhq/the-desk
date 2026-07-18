// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App, Component } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import GifPickerPanel from './GifPickerPanel.vue';

vi.mock('vue-sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

vi.mock('@/components/ui/button', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Button: defineComponent({
            name: 'ButtonStub',
            inheritAttrs: false,
            setup:
                (_props, { attrs, slots }) =>
                () =>
                    h('button', attrs, slots.default?.()),
        }),
    };
});

function gif(id: string): App.Data.GiphyGifData {
    return {
        id,
        url: `https://media.giphy.com/${id}/200.gif`,
        previewUrl: `https://media.giphy.com/${id}/100.gif`,
        width: 2,
        height: 1,
        description: `gif ${id}`,
    };
}

let active: Array<{ app: App; container: HTMLElement }> = [];

function mountPanel(options: {
    searchGifs?: (
        url: string,
        signal?: AbortSignal,
    ) => Promise<App.Data.GiphySearchData>;
    attachGif?: (url: string, id: string) => Promise<unknown>;
    initialQuery?: string;
}) {
    const selected: unknown[] = [];
    const closed: number[] = [];
    const container = document.createElement('div');
    document.body.appendChild(container);

    const app = createApp(
        defineComponent({
            setup: () => () =>
                h(GifPickerPanel as Component, {
                    teamSlug: 'acme',
                    channelSlug: 'general',
                    initialQuery: options.initialQuery ?? '',
                    debounceMs: 0,
                    searchGifs: options.searchGifs,
                    attachGif: options.attachGif,
                    onSelect: (attachment: unknown) =>
                        selected.push(attachment),
                    onClose: () => closed.push(1),
                }),
        }),
    );
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(container);
    active.push({ app, container });

    return { container, selected, closed };
}

function optionEls(container: HTMLElement): HTMLElement[] {
    return Array.from(
        container.querySelectorAll<HTMLElement>('[data-test="gif-option"]'),
    );
}

/**
 * Settle the panel. Two macrotasks: the first lets the query watcher schedule
 * its (0ms) debounce timer, the second lets that timer fire; then the
 * search/attach promise chain's microtasks resolve.
 */
async function flush(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await new Promise((resolve) => setTimeout(resolve, 0));
    await nextTick();
    await Promise.resolve();
    await Promise.resolve();
    await nextTick();
}

afterEach(() => {
    active.forEach(({ app, container }) => {
        app.unmount();
        container.remove();
    });
    active = [];
    vi.clearAllMocks();
});

describe('GifPickerPanel', () => {
    it('loads trending on open and renders the grid', async () => {
        const searchGifs = vi.fn().mockResolvedValue({
            results: [gif('a'), gif('b')],
            nextOffset: null,
        });

        const { container } = mountPanel({ searchGifs });
        await flush();

        expect(optionEls(container)).toHaveLength(2);
        expect(
            container.querySelector('[data-test="gif-powered-by"]'),
        ).not.toBeNull();
        // A blank query means trending: no `q` in the requested URL.
        expect(searchGifs.mock.calls[0][0]).not.toContain('q=');
    });

    it('debounced-searches with the typed query', async () => {
        const searchGifs = vi
            .fn()
            .mockResolvedValue({ results: [gif('a')], nextOffset: null });

        const { container } = mountPanel({ searchGifs });
        await flush();

        const input = container.querySelector<HTMLInputElement>(
            '[data-test="gif-search-input"]',
        )!;
        input.value = 'cats';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await flush();

        expect(searchGifs.mock.calls.at(-1)?.[0]).toContain('q=cats');
    });

    it('attaches a picked GIF and emits it', async () => {
        const attachment = { id: 'att-1', source: 'giphy' };
        const attachGif = vi.fn().mockResolvedValue(attachment);

        const { container, selected } = mountPanel({
            searchGifs: vi
                .fn()
                .mockResolvedValue({ results: [gif('a')], nextOffset: null }),
            attachGif,
        });
        await flush();

        optionEls(container)[0].click();
        await flush();

        expect(attachGif).toHaveBeenCalledWith(expect.any(String), 'a');
        expect(selected).toEqual([attachment]);
    });

    it('shows an empty state when there are no results', async () => {
        const { container } = mountPanel({
            searchGifs: vi
                .fn()
                .mockResolvedValue({ results: [], nextOffset: null }),
        });
        await flush();

        expect(
            container.querySelector('[data-test="gif-empty"]'),
        ).not.toBeNull();
    });

    it('shows an error state when the search fails', async () => {
        const { container } = mountPanel({
            searchGifs: vi.fn().mockRejectedValue(new Error('boom')),
        });
        await flush();

        expect(
            container.querySelector('[data-test="gif-error"]'),
        ).not.toBeNull();
    });

    it('closes on Escape', async () => {
        const { container, closed } = mountPanel({
            searchGifs: vi
                .fn()
                .mockResolvedValue({ results: [gif('a')], nextOffset: null }),
        });
        await flush();

        container
            .querySelector('[data-test="gif-picker"]')!
            .dispatchEvent(
                new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
            );
        await nextTick();

        expect(closed).toEqual([1]);
    });

    it('appends the next page on scroll to the end', async () => {
        const searchGifs = vi
            .fn()
            .mockResolvedValueOnce({ results: [gif('a')], nextOffset: 1 })
            .mockResolvedValueOnce({ results: [gif('b')], nextOffset: null });

        const { container } = mountPanel({ searchGifs });
        await flush();
        expect(optionEls(container)).toHaveLength(1);

        container
            .querySelector('[role="listbox"]')!
            .dispatchEvent(new Event('scroll'));
        await flush();

        expect(optionEls(container)).toHaveLength(2);
        expect(searchGifs.mock.calls[1][0]).toContain('offset=1');
    });
});
