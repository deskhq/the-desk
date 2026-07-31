import type { App, Component } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import MessageComposer from '@/components/MessageComposer.vue';
import { releaseChannelUploads } from '@/composables/useChannelUploads';
import { translate } from '@/lib/i18n';

/**
 * The harness the phone-layout composer suite mounts against, so the tests read
 * as behaviour rather than scaffolding. The stubs its `vi.mock` factories return
 * live in MessageComposer.mobile.stubs.ts, which must stay free of app imports.
 */

/**
 * Put the media-query engine at a given width, which is what tells the composer
 * which layout to render. jsdom ships a `matchMedia` that answers every query
 * with false, so nothing below the breakpoint would ever appear.
 */
export function atViewportWidth(width: number): void {
    window.matchMedia = ((query: string) => {
        const max = Number(/max-width:\s*([\d.]+)px/.exec(query)?.[1]);

        return {
            media: query,
            matches: width <= max,
            onchange: null,
            addEventListener: () => {},
            removeEventListener: () => {},
            addListener: () => {},
            removeListener: () => {},
            dispatchEvent: () => false,
        };
    }) as typeof window.matchMedia;
}

const active: Array<{ app: App; container: HTMLElement }> = [];

export function mountComposer(
    props: Record<string, unknown> = {},
): HTMLElement {
    const container = document.createElement('div');
    document.body.append(container);

    const app = createApp(
        defineComponent({
            setup: () => () =>
                h(MessageComposer as Component, {
                    channelName: 'general',
                    members: [],
                    teamSlug: 'acme',
                    channelSlug: 'general',
                    maxAttachmentSizeMb: 25,
                    maxAttachmentsPerMessage: 10,
                    ...props,
                }),
        }),
    );
    app.config.globalProperties.$t = translate;
    app.mount(container);
    active.push({ app, container });

    return container;
}

export function unmountAll(): void {
    active.splice(0).forEach(({ app, container }) => {
        app.unmount();
        container.remove();
    });
    releaseChannelUploads();
}

export function find(container: HTMLElement, hook: string): HTMLElement | null {
    return container.querySelector<HTMLElement>(`[data-test="${hook}"]`);
}

export function field(container: HTMLElement): HTMLTextAreaElement {
    return container.querySelector<HTMLTextAreaElement>(
        '[data-test="message-composer-input"]',
    )!;
}

function tileElements(container: HTMLElement): HTMLElement[] {
    return [
        ...container.querySelectorAll<HTMLElement>(
            '[data-test="composer-attach-tile"]',
        ),
    ];
}

/** The `data-tile` of every tile the sheet is currently offering, in order. */
export function tiles(container: HTMLElement): string[] {
    return tileElements(container).map((element) => element.dataset.tile!);
}

/** The tile carrying a given `data-tile`, which the sheet must be showing. */
export function tile(container: HTMLElement, name: string): HTMLElement {
    return tileElements(container).find(
        (candidate) => candidate.dataset.tile === name,
    )!;
}

export async function openSheet(container: HTMLElement): Promise<void> {
    find(container, 'composer-attach-sheet-toggle')!.click();
    await nextTick();
}

/** Type into the field the way the user does, caret past the last character. */
export async function type(
    container: HTMLElement,
    value: string,
): Promise<void> {
    const el = field(container);
    el.value = value;
    el.setSelectionRange(value.length, value.length);
    el.dispatchEvent(new Event('input', { bubbles: true }));

    await nextTick();
}

/** Set a hidden input's `files` (read-only in jsdom) and fire `change`. */
export async function stage(
    container: HTMLElement,
    hook: string,
    file: File,
): Promise<void> {
    await fireChange(container, hook, [file]);
}

/**
 * Fire `change` on a hidden input that carries no file, which is what a browser
 * does when the picker (or the camera) is dismissed after an earlier pick.
 */
export async function stageNothing(
    container: HTMLElement,
    hook: string,
): Promise<void> {
    await fireChange(container, hook, []);
}

async function fireChange(
    container: HTMLElement,
    hook: string,
    files: File[],
): Promise<void> {
    const input = container.querySelector<HTMLInputElement>(
        `[data-test="${hook}"]`,
    )!;
    Object.defineProperty(input, 'files', {
        value: {
            ...files,
            length: files.length,
            item: (index: number) => files[index] ?? null,
        },
        configurable: true,
    });
    input.dispatchEvent(new Event('change', { bubbles: true }));

    await nextTick();
}
