// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { App, Component } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';
import type { SendCallbacks } from '@/composables/useMessageActions';
import type { UploadHandle } from '@/lib/uploadAttachment';
import MessageComposer from './MessageComposer.vue';

/**
 * Proves the composer's failed-online-send contract at the layer that can hold
 * it deterministically: mount the real `<MessageComposer>`, stage an attachment
 * through the real `useAttachmentUploads`, send, and drive the send's outcome
 * hooks by hand.
 *
 * On send the tray is emptied optimistically. A rejected online send must hand
 * the staged attachment (and the typed body) back so the user can retry without
 * re-picking every file. `useAttachmentUploads`' snapshot restore is unit-tested
 * on its own; this covers the wiring in the composer's `submit()` that snapshots,
 * emits the outcome hooks, and restores through `onRejected`.
 *
 * This used to be a browser test, but the pest-browser in-process server has no
 * multipart handling (LaravelHttpServer only parses urlencoded bodies), so the
 * attachment pre-upload always 422'd and the chip vanished before the send could
 * even be attempted. That path is untestable there; the transport is mocked here
 * instead so the real staging → detach → restore flow runs.
 */

// Control the upload transport so a staged file settles to `done` on demand.
const uploads = vi.hoisted(
    () => [] as Array<{ resolve: (value: unknown) => void }>,
);

vi.mock('@/lib/uploadAttachment', () => ({
    xhrUpload: (): UploadHandle => {
        let resolve!: (value: unknown) => void;
        const promise = new Promise((res) => {
            resolve = res;
        });
        uploads.push({ resolve });

        return { promise: promise as Promise<never>, abort: vi.fn() };
    },
}));

vi.mock('@/actions/App/Http/Controllers/Channels/AttachmentController', () => ({
    store: () => ({ url: '/t/acme/c/general/attachments' }),
}));

// The composer's chrome is irrelevant to the restore wiring; stub the heavy
// children down to slot pass-throughs so the composer's own template renders.
// A real <button> keeps the send button's `@click`/`disabled`/`data-test`.
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

vi.mock('@/components/ScheduleMessageDialog', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            name: 'ScheduleMessageDialogStub',
            setup:
                (_props, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        }),
    };
});

vi.mock('@/components/MessageQuote', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            name: 'MessageQuoteStub',
            setup:
                (_props, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        }),
    };
});

let active: Array<{ app: App; container: HTMLElement }> = [];

function attachmentDto(id: string) {
    return {
        id,
        filename: 'launch-checklist.txt',
        mimeType: 'text/plain',
        sizeBytes: 24,
        width: null,
        height: null,
        isImage: false,
        url: `/download/${id}`,
        thumbUrl: null,
    };
}

function mountComposer() {
    const sent: Array<{
        body: string;
        attachmentIds: string[];
        callbacks: SendCallbacks;
    }> = [];
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
                    onSend: (
                        body: string,
                        _mentions: unknown,
                        _toChannel: boolean,
                        attachmentIds: string[],
                        callbacks: SendCallbacks,
                    ) => sent.push({ body, attachmentIds, callbacks }),
                }),
        }),
    );
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(container);
    active.push({ app, container });

    return { container, sent };
}

/** Set an input's `files` (read-only in jsdom) and fire the `change` listeners. */
function stageFile(input: HTMLInputElement, file: File): void {
    const list = {
        0: file,
        length: 1,
        item: (index: number) => (index === 0 ? file : null),
    };
    Object.defineProperty(input, 'files', { value: list, configurable: true });
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

afterEach(() => {
    active.forEach(({ app, container }) => {
        app.unmount();
        container.remove();
    });
    active = [];
    uploads.length = 0;
});

describe('MessageComposer failed-send attachment restore', () => {
    it('hands the staged attachment and body back when an online send is rejected', async () => {
        const { container, sent } = mountComposer();

        const fileInput = container.querySelector<HTMLInputElement>(
            '[data-test="composer-file-input"]',
        )!;
        const textarea = container.querySelector<HTMLTextAreaElement>(
            '[data-test="message-composer-input"]',
        )!;

        // Stage a file and settle its pre-upload so the tray holds a ready chip.
        stageFile(
            fileInput,
            new File(['launch checklist contents'], 'launch-checklist.txt', {
                type: 'text/plain',
            }),
        );
        await nextTick();
        expect(uploads).toHaveLength(1);
        uploads[0].resolve(attachmentDto('att-1'));
        await nextTick();

        const chip = () =>
            container.querySelector('[data-test="composer-attachment"]');
        expect(chip()).not.toBeNull();

        // Type a body and send.
        textarea.value = 'Here you go';
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        await nextTick();

        container
            .querySelector<HTMLButtonElement>(
                '[data-test="message-composer-send"]',
            )!
            .click();
        await nextTick();

        // The send fired with the staged attachment, and the tray emptied
        // optimistically while the outcome is pending.
        expect(sent).toHaveLength(1);
        expect(sent[0].attachmentIds).toEqual(['att-1']);
        expect(sent[0].body).toBe('Here you go');
        expect(chip()).toBeNull();
        expect(textarea.value).toBe('');

        // The online send is rejected: the composer must restore both.
        sent[0].callbacks.onRejected?.();
        await nextTick();

        expect(chip()).not.toBeNull();
        expect(container.textContent).toContain('launch-checklist.txt');
        expect(textarea.value).toBe('Here you go');
    });

    it('drops the staged snapshot when the send is accepted', async () => {
        const { container, sent } = mountComposer();

        const fileInput = container.querySelector<HTMLInputElement>(
            '[data-test="composer-file-input"]',
        )!;
        const textarea = container.querySelector<HTMLTextAreaElement>(
            '[data-test="message-composer-input"]',
        )!;

        stageFile(
            fileInput,
            new File(['launch checklist contents'], 'launch-checklist.txt', {
                type: 'text/plain',
            }),
        );
        await nextTick();
        uploads[0].resolve(attachmentDto('att-1'));
        await nextTick();

        textarea.value = 'Here you go';
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        await nextTick();

        container
            .querySelector<HTMLButtonElement>(
                '[data-test="message-composer-send"]',
            )!
            .click();
        await nextTick();

        // A successful send disposes the snapshot: the tray stays empty.
        sent[0].callbacks.onAccepted?.();
        await nextTick();

        expect(
            container.querySelector('[data-test="composer-attachment"]'),
        ).toBeNull();
        expect(textarea.value).toBe('');
    });
});
