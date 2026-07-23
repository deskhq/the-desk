// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { App } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';

import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';

/**
 * Renders a real `<Dialog>`/`<DialogContent>` pair under jsdom at a chosen
 * viewport, so the presentation the primitive picks — a bottom sheet on a phone,
 * a centred dialog from `md` up — is what the tests read, rather than a
 * hand-copied class string.
 */
let app: App | null = null;

/**
 * jsdom has no `matchMedia`. Answering the app's one breakpoint query from a
 * width lets a test stand at any viewport it likes.
 */
function standAt(width: number): void {
    window.matchMedia = ((query: string) => {
        const limit = Number(/max-width:\s*([\d.]+)px/.exec(query)?.[1] ?? NaN);

        return {
            matches: Number.isNaN(limit) ? false : width <= limit,
            media: query,
            addEventListener: () => {},
            removeEventListener: () => {},
            addListener: () => {},
            removeListener: () => {},
            onchange: null,
            dispatchEvent: () => false,
        };
    }) as typeof window.matchMedia;
}

async function open(
    mobile?: 'sheet' | 'detail' | 'dialog' | 'fullscreen',
): Promise<HTMLElement> {
    app = createApp(
        defineComponent({
            setup: () => () =>
                h(Dialog, { defaultOpen: true }, () => [
                    h(DialogContent, { mobile, class: 'sm:max-w-md' }, () => [
                        h(DialogTitle, () => 'Create a channel'),
                    ]),
                ]),
        }),
    );
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(document.createElement('div'));

    await nextTick();
    await nextTick();

    const content = document.querySelector<HTMLElement>(
        '[data-slot="dialog-content"]',
    );

    expect(content).not.toBeNull();

    return content as HTMLElement;
}

/** jsdom ships no `matchMedia`, so this is `undefined` — put back either way. */
const realMatchMedia = window.matchMedia;

beforeEach(() => standAt(390));

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
    window.matchMedia = realMatchMedia;
});

describe('below the md breakpoint', () => {
    it('presents as a bottom sheet with the design’s chrome', async () => {
        const content = await open();

        expect(content.className).toContain('bottom-0');
        expect(content.className).toContain('rounded-t-[20px]');
        expect(content.className).toContain('border-t');
        // The sheet grows with its content up to the cap rather than being pinned.
        expect(content.style.maxHeight).toBe('calc(85dvh - 0px)');
        expect(content.style.height).toBe('');
    });

    it('beats whatever width the call site set for its desktop dialog', async () => {
        // `sm:` opens at 640px, below the breakpoint: a sheet that respected it
        // would stop short of the screen edge on a landscape phone.
        expect((await open()).style.maxWidth).toBe('none');
    });

    it('offers a grab handle to drag it away by', async () => {
        const content = await open();

        expect(
            content.querySelector('[data-test="sheet-grab-handle"]'),
        ).not.toBeNull();
    });

    it('pins a detail sheet to 85% of the screen', async () => {
        // The stand-in for a desktop right-hand pane: a fixed height, so a list
        // does not resize under the thumb as it is worked through.
        expect((await open('detail')).style.height).toBe('calc(85dvh - 0px)');
    });

    it('fills the screen edge-to-edge for a fullscreen overlay', async () => {
        const content = await open('fullscreen');

        expect(content.className).toContain('inset-0');
        // No sheet chrome: an overlay that is the screen has nothing to round
        // off or drag away by.
        expect(content.className).not.toContain('rounded-t-[20px]');
        expect(
            content.querySelector('[data-test="sheet-grab-handle"]'),
        ).toBeNull();
        // The bottom tracks the on-screen keyboard so the list's end stays
        // reachable while typing (0 with no keyboard up).
        expect(content.style.bottom).toBe('0px');
    });

    it('leaves an opted-out dialog centred, with no handle', async () => {
        const content = await open('dialog');

        expect(content.className).toContain('translate-x-[-50%]');
        expect(content.style.maxWidth).toBe('');
        expect(
            content.querySelector('[data-test="sheet-grab-handle"]'),
        ).toBeNull();
    });
});

describe('from the md breakpoint up', () => {
    beforeEach(() => standAt(1280));

    it('keeps the centred dialog untouched', async () => {
        const content = await open();

        expect(content.className).toContain('translate-x-[-50%]');
        expect(content.className).toContain('sm:max-w-md');
        expect(content.className).not.toContain('rounded-t-[20px]');
        // No sizing is imposed inline, so the call site's own classes still decide.
        expect(content.style.maxWidth).toBe('');
        expect(content.style.maxHeight).toBe('');
        expect(content.style.height).toBe('');
        expect(
            content.querySelector('[data-test="sheet-grab-handle"]'),
        ).toBeNull();
    });

    it('keeps a fullscreen-below-md dialog centred from md up', async () => {
        const content = await open('fullscreen');

        expect(content.className).toContain('translate-x-[-50%]');
        expect(content.className).not.toContain('inset-0');
    });
});
