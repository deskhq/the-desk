// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { App, VNodeChild } from 'vue';
import { createApp, defineComponent, h, nextTick, ref } from 'vue';

import {
    Dialog,
    DialogContent,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { initializeOverlayInert } from '@/lib/overlayInert';

/**
 * The restore has to survive the `inert` the app mirrors over the page behind an
 * open dialog, so these render a real `<Dialog>` with that mirror running rather
 * than stubbing either half.
 */
let app: App | null = null;
let disposeOverlayInert: (() => void) | null = null;

/** jsdom has no `matchMedia`; the dialog primitive asks it for the breakpoint. */
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

const nativeFocus = HTMLElement.prototype.focus;

/**
 * jsdom implements `focus()` without the platform's `inert` rule: it will focus
 * an element inside an inert subtree, which a browser silently refuses to do.
 * That refusal *is* the defect under test (#784), so the rule is put back here —
 * without it a restore that fires too early would look like it had worked.
 */
function enforceInertFocusRule(): void {
    HTMLElement.prototype.focus = function focus(
        this: HTMLElement,
        options?: FocusOptions,
    ): void {
        if (this.closest('[inert]')) {
            return;
        }

        nativeFocus.call(this, options);
    };
}

/**
 * Let Vue, the overlay-inert `MutationObserver` (a microtask) and the deferred
 * restore (a task) all land before anything is read.
 */
async function settle(): Promise<void> {
    for (let round = 0; round < 4; round += 1) {
        await nextTick();
        await new Promise((resolve) => setTimeout(resolve));
    }
}

/**
 * Mount `tree`, open the dialog by clicking the control it marked `@opener`, and
 * hand both that control and a way to close back to the test.
 */
async function openDialog(
    tree: (open: boolean, onUpdate: (value: boolean) => void) => VNodeChild,
): Promise<{ opener: HTMLElement; close: () => void }> {
    const open = ref(false);

    app = createApp(
        defineComponent({
            setup: () => () =>
                tree(open.value, (value: boolean) => {
                    open.value = value;
                }),
        }),
    );
    app.config.globalProperties.$t = (key: string) => key;

    const host = document.createElement('div');
    document.body.append(host);
    app.mount(host);
    await nextTick();

    const opener = document.querySelector<HTMLElement>('[data-test="opener"]');

    expect(opener).not.toBeNull();

    opener!.focus();
    opener!.click();
    await settle();

    return {
        opener: opener as HTMLElement,
        close: () => {
            open.value = false;
        },
    };
}

/** The dialog's own half of the tree, shared by every shape of opener. */
const content = (): VNodeChild =>
    h(DialogContent, null, () => [h(DialogTitle, () => 'Create a channel')]);

beforeEach(() => {
    standAt(1280);
    enforceInertFocusRule();
    disposeOverlayInert = initializeOverlayInert();
});

afterEach(() => {
    app?.unmount();
    app = null;
    disposeOverlayInert?.();
    disposeOverlayInert = null;
    HTMLElement.prototype.focus = nativeFocus;
    document.body.innerHTML = '';
});

describe('closing a dialog', () => {
    it('returns focus to the button that opened it', async () => {
        // An ordinary button driving `v-model:open` — how nearly every dialog
        // in this app is opened, and the shape reka's own restore misses.
        const { opener, close } = await openDialog((open, onUpdate) => [
            h(
                'button',
                {
                    type: 'button',
                    'data-test': 'opener',
                    onClick: () => onUpdate(true),
                },
                'Open',
            ),
            h(Dialog, { open, 'onUpdate:open': onUpdate }, content),
        ]);

        // The page behind the dialog is inert while it is open — the state the
        // restore has to outlive.
        expect(opener.closest('[inert]')).not.toBeNull();

        close();
        await settle();

        expect(document.activeElement).toBe(opener);
    });

    it('returns focus to a real DialogTrigger too', async () => {
        const { opener, close } = await openDialog((open, onUpdate) =>
            h(Dialog, { open, 'onUpdate:open': onUpdate }, () => [
                h(DialogTrigger, { asChild: true }, () => [
                    h(
                        'button',
                        { type: 'button', 'data-test': 'opener' },
                        'Open',
                    ),
                ]),
                content(),
            ]),
        );

        close();
        await settle();

        expect(document.activeElement).toBe(opener);
    });

    it('leaves a call site that names its own destination alone', async () => {
        const elsewhere = document.createElement('button');
        document.body.append(elsewhere);

        const { close } = await openDialog((open, onUpdate) => [
            h(
                'button',
                {
                    type: 'button',
                    'data-test': 'opener',
                    onClick: () => onUpdate(true),
                },
                'Open',
            ),
            h(Dialog, { open, 'onUpdate:open': onUpdate }, () => [
                h(
                    DialogContent,
                    {
                        onCloseAutoFocus: (event: Event) => {
                            event.preventDefault();
                            setTimeout(() => elsewhere.focus());
                        },
                    },
                    () => [h(DialogTitle, () => 'Create a channel')],
                ),
            ]),
        ]);

        close();
        await settle();

        expect(document.activeElement).toBe(elsewhere);
    });
});
