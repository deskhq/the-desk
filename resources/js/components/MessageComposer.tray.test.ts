// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App, Component } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import type { UploadHandle } from '@/lib/uploadAttachment';
import MessageComposer from './MessageComposer.vue';

/**
 * Covers the pre-send attachment tray's chips: which shape each staged row
 * renders as (image, file, failed), the progress it reports while uploading,
 * and the remove/retry controls on it. `useAttachmentUploads` is unit-tested on
 * its own; what is watched here is the markup the composer wraps it in.
 */

type PendingUpload = {
    resolve: (value: unknown) => void;
    reject: (reason: unknown) => void;
    progress: (percent: number) => void;
};

/** Control the upload transport so a staged file settles on demand. */
const uploads = vi.hoisted(() => [] as PendingUpload[]);

vi.mock('@/lib/uploadAttachment', () => ({
    xhrUpload: (
        _endpoint: string,
        _file: File,
        onProgress: (percent: number) => void,
    ): UploadHandle => {
        let resolve!: (value: unknown) => void;
        let reject!: (reason: unknown) => void;
        const promise = new Promise((res, rej) => {
            resolve = res;
            reject = rej;
        });
        uploads.push({ resolve, reject, progress: onProgress });

        return { promise: promise as Promise<never>, abort: vi.fn() };
    },
}));

vi.mock('@/actions/App/Http/Controllers/Channels/AttachmentController', () => ({
    store: () => ({ url: '/t/acme/c/general/attachments' }),
}));

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

vi.mock('@/components/ui/tooltip', async () => {
    const { defineComponent, h } = await import('vue');
    const slot = (name: string) =>
        defineComponent({
            name,
            setup:
                (_props, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        });

    return {
        Tooltip: slot('TooltipStub'),
        TooltipContent: slot('TooltipContentStub'),
        TooltipProvider: slot('TooltipProviderStub'),
        TooltipTrigger: slot('TooltipTriggerStub'),
    };
});

const PREVIEW_URL = 'blob:preview-1';

beforeEach(() => {
    URL.createObjectURL = vi.fn(() => PREVIEW_URL);
    URL.revokeObjectURL = vi.fn();
});

let active: Array<{ app: App; container: HTMLElement }> = [];

function mountComposer(props: Record<string, unknown> = {}) {
    const container = document.createElement('div');
    document.body.appendChild(container);

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
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(container);
    active.push({ app, container });

    return { container };
}

/** Set the hidden input's `files` (read-only in jsdom) and fire `change`. */
async function stage(container: HTMLElement, file: File): Promise<void> {
    const input = container.querySelector<HTMLInputElement>(
        '[data-test="composer-file-input"]',
    )!;
    const list = {
        0: file,
        length: 1,
        item: (index: number) => (index === 0 ? file : null),
    };
    Object.defineProperty(input, 'files', { value: list, configurable: true });
    input.dispatchEvent(new Event('change', { bubbles: true }));

    await nextTick();
}

function chips(container: HTMLElement): HTMLElement[] {
    return Array.from(
        container.querySelectorAll<HTMLElement>(
            '[data-test="composer-attachment"]',
        ),
    );
}

function textFile(): File {
    return new File(['x'.repeat(2048)], 'launch-checklist.txt', {
        type: 'text/plain',
    });
}

function imageFile(): File {
    return new File(['png'], 'diagram.png', { type: 'image/png' });
}

/** Settle the mocked upload promise's `.then` handlers. */
async function settle(): Promise<void> {
    await Promise.resolve();
    await nextTick();
}

afterEach(() => {
    active.forEach(({ app, container }) => {
        app.unmount();
        container.remove();
    });
    active = [];
    uploads.length = 0;
});

describe('MessageComposer attachment tray', () => {
    it('stays out of the way until something is staged', () => {
        const { container } = mountComposer();

        expect(
            container.querySelector('[data-test="composer-attachment-tray"]'),
        ).toBeNull();
    });

    it('offers no attachments where the composer does not know its channel', () => {
        const { container } = mountComposer({ channelSlug: undefined });

        expect(
            container
                .querySelector('[data-test="message-composer-attach"]')
                ?.hasAttribute('disabled'),
        ).toBe(true);
    });

    it('reports a file’s name, size and progress while it uploads', async () => {
        const { container } = mountComposer();

        await stage(container, textFile());
        uploads[0].progress(40);
        await nextTick();

        const [chip] = chips(container);
        expect(chip.getAttribute('data-kind')).toBe('file');
        expect(chip.getAttribute('data-status')).toBe('uploading');
        expect(chip.textContent).toContain('launch-checklist.txt');
        expect(chip.textContent).toContain('2 KB');
        expect(chip.textContent).toContain('40%');
    });

    it('settles a finished upload into a plain chip', async () => {
        const { container } = mountComposer();

        await stage(container, textFile());
        uploads[0].resolve({ id: 'att-1' });
        await settle();

        const [chip] = chips(container);
        expect(chip.getAttribute('data-status')).toBe('done');
        expect(chip.textContent).not.toContain('%');
    });

    it('previews a staged image as a thumbnail', async () => {
        const { container } = mountComposer();

        await stage(container, imageFile());

        const [chip] = chips(container);
        expect(chip.getAttribute('data-kind')).toBe('image');
        expect(chip.querySelector('img')?.getAttribute('src')).toBe(
            PREVIEW_URL,
        );
        // The thumbnail's remove control is a painted badge rather than a
        // visible button, so the picture it belongs to stays readable.
        expect(
            chip.querySelector(
                '[data-test="composer-attachment-remove-badge"]',
            ),
        ).not.toBeNull();
    });

    it('offers a retry on a transport failure', async () => {
        const { container } = mountComposer();

        await stage(container, textFile());
        uploads[0].reject({ aborted: false, message: null, status: 500 });
        await settle();

        const [failed] = chips(container);
        expect(failed.getAttribute('data-kind')).toBe('failed');
        expect(failed.textContent).toContain('Upload failed');

        failed
            .querySelector<HTMLButtonElement>(
                '[data-test="composer-attachment-retry"]',
            )!
            .click();
        await nextTick();

        expect(chips(container)[0].getAttribute('data-status')).toBe(
            'uploading',
        );
        expect(uploads).toHaveLength(2);
    });

    it('drops a staged row from its remove control', async () => {
        const { container } = mountComposer();

        await stage(container, textFile());
        chips(container)[0]
            .querySelector<HTMLButtonElement>(
                '[data-test="composer-attachment-remove"]',
            )!
            .click();
        await nextTick();

        expect(chips(container)).toHaveLength(0);
        expect(
            container.querySelector('[data-test="composer-attachment-tray"]'),
        ).toBeNull();
    });

    it('blocks the send while a row is still uploading or failed', async () => {
        const { container } = mountComposer();
        const send = container.querySelector<HTMLButtonElement>(
            '[data-test="message-composer-send"]',
        )!;

        await stage(container, textFile());
        expect(send.hasAttribute('disabled')).toBe(true);

        uploads[0].resolve({ id: 'att-1' });
        await settle();
        expect(send.hasAttribute('disabled')).toBe(false);
    });

    it('keeps saving drafts after an attachment-only send', async () => {
        const drafts: string[] = [];
        const { container } = mountComposer({
            onDraftChange: (body: string) => drafts.push(body),
        });

        // An attachment-only send leaves the body empty throughout, so the
        // clear-on-send assigns `''` to an already-empty field and fires no
        // watcher — the draft guard must not be armed for a change that will
        // never come, or it swallows the next genuine keystroke instead.
        await stage(container, textFile());
        uploads[0].resolve({ id: 'att-1' });
        await settle();

        container
            .querySelector<HTMLButtonElement>(
                '[data-test="message-composer-send"]',
            )!
            .click();
        await nextTick();

        const textarea = container.querySelector<HTMLTextAreaElement>(
            '[data-test="message-composer-input"]',
        )!;
        textarea.value = 'a';
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        await nextTick();

        expect(drafts).toEqual(['a']);
    });
});
