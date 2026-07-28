import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { EffectScope } from 'vue';

/**
 * Covers what reaches the tray and what it reports while it fills: the rows a
 * dropped file and a picked GIF stage, the previews each kind gets, the size
 * and per-message limits that turn a file away, and the progress and ids the
 * composer reads back. The outcomes an upload can reach afterwards live in
 * `useAttachmentUploads.failures`, the send-time snapshot in
 * `useAttachmentUploads.detach`.
 */
const { toastError } = vi.hoisted(() => ({ toastError: vi.fn() }));

vi.mock('@/composables/useToast', () => {
    const toast = {
        error: toastError,
        success: vi.fn(),
        warning: vi.fn(),
        progress: vi.fn(),
    };

    return { useToast: () => toast };
});

import type { AttachmentUploads } from '@/composables/useAttachmentUploads';
import {
    MB,
    attachment,
    buildUploads,
    fakeFile,
} from './useAttachmentUploads.doubles';
import type { Deferred } from './useAttachmentUploads.doubles';

describe('useAttachmentUploads', () => {
    let scope: EffectScope;
    let uploads: AttachmentUploads;
    let calls: Deferred[];

    beforeEach(() => {
        toastError.mockClear();
        ({ scope, uploads, calls } = buildUploads());
    });

    afterEach(() => {
        scope.stop();
    });

    it('stages a dropped file as an uploading row and posts it to the endpoint', () => {
        uploads.addFiles([fakeFile('a.pdf', 'application/pdf')]);

        expect(uploads.items.value).toHaveLength(1);
        expect(uploads.items.value[0].status).toBe('uploading');
        expect(uploads.isUploading.value).toBe(true);
        expect(calls).toHaveLength(1);
        expect(calls[0].url).toBe('/t/acme/c/design/attachments');
    });

    it('stages a picked GIF as a ready remote row carrying its claimable id', () => {
        uploads.addRemote({
            id: 'gif-1',
            filename: null,
            mimeType: 'image/gif',
            sizeBytes: 0,
            width: 2,
            height: 1,
            isImage: true,
            source: 'giphy',
            url: 'https://media.giphy.com/gif-1/200.gif',
            thumbUrl: null,
            description: 'a happy cat',
        });

        expect(uploads.items.value).toHaveLength(1);
        const row = uploads.items.value[0];
        expect(row.status).toBe('done');
        expect(row.isImage).toBe(true);
        // A remote GIF previews straight from its CDN url (no local blob).
        expect(row.previewUrl).toBe('https://media.giphy.com/gif-1/200.gif');
        expect(row.name).toBe('a happy cat');
        expect(uploads.isUploading.value).toBe(false);
        expect(uploads.attachmentIds.value).toEqual(['gif-1']);
        // No upload is fired for an already-created remote attachment.
        expect(calls).toHaveLength(0);
    });

    it('honours the per-message cap for picked GIFs', () => {
        for (let i = 0; i < 3; i++) {
            uploads.addRemote({
                id: `gif-${i}`,
                filename: null,
                mimeType: 'image/gif',
                sizeBytes: 0,
                width: 2,
                height: 1,
                isImage: true,
                source: 'giphy',
                url: `https://media.giphy.com/gif-${i}/200.gif`,
                thumbUrl: null,
                description: null,
            });
        }

        uploads.addRemote({
            id: 'gif-over',
            filename: null,
            mimeType: 'image/gif',
            sizeBytes: 0,
            width: 2,
            height: 1,
            isImage: true,
            source: 'giphy',
            url: 'https://media.giphy.com/over/200.gif',
            thumbUrl: null,
            description: null,
        });

        expect(uploads.items.value).toHaveLength(3);
        expect(toastError).toHaveBeenCalledOnce();
    });

    it('previews an image row via an object URL but not a plain file', () => {
        uploads.addFiles([
            fakeFile('pic.png', 'image/png'),
            fakeFile('doc.pdf', 'application/pdf'),
        ]);

        expect(uploads.items.value[0].isImage).toBe(true);
        expect(uploads.items.value[0].previewUrl).toBe('blob:pic.png');
        expect(uploads.items.value[1].isImage).toBe(false);
        expect(uploads.items.value[1].previewUrl).toBeNull();
    });

    it('previews an audio row via an object URL so the tray can play it back', () => {
        uploads.addFiles([
            fakeFile('voice-message-1721318675.webm', 'audio/webm;codecs=opus'),
            fakeFile('standup-jingle.mp3', 'audio/mpeg'),
            fakeFile('doc.pdf', 'application/pdf'),
        ]);

        expect(uploads.items.value[0].isAudio).toBe(true);
        expect(uploads.items.value[0].isImage).toBe(false);
        expect(uploads.items.value[0].previewUrl).toBe(
            'blob:voice-message-1721318675.webm',
        );
        expect(uploads.items.value[1].isAudio).toBe(true);
        expect(uploads.items.value[2].isAudio).toBe(false);
        expect(uploads.items.value[2].previewUrl).toBeNull();
    });

    it('tracks upload progress on the row', () => {
        uploads.addFiles([fakeFile('a.pdf', 'application/pdf')]);
        calls[0].onProgress(64);

        expect(uploads.items.value[0].progress).toBe(64);
    });

    it('marks a row done and exposes its id in tray order once uploaded', async () => {
        uploads.addFiles([
            fakeFile('a.pdf', 'application/pdf'),
            fakeFile('b.pdf', 'application/pdf'),
        ]);

        calls[0].resolve(attachment('att-1'));
        calls[1].resolve(attachment('att-2'));
        await Promise.resolve();
        await Promise.resolve();

        expect(uploads.items.value.map((i) => i.status)).toEqual([
            'done',
            'done',
        ]);
        expect(uploads.attachmentIds.value).toEqual(['att-1', 'att-2']);
        expect(uploads.isUploading.value).toBe(false);
    });

    it('rejects an oversized file with a toast and never stages it', () => {
        uploads.addFiles([fakeFile('huge.mov', 'video/quicktime', 30 * MB)]);

        expect(uploads.items.value).toHaveLength(0);
        expect(calls).toHaveLength(0);
        expect(toastError).toHaveBeenCalledTimes(1);
        expect(toastError.mock.calls[0][0]).toContain('huge.mov');
    });

    it('caps the tray at the per-message limit, keeping the first files', () => {
        uploads.addFiles([
            fakeFile('1.pdf', 'application/pdf'),
            fakeFile('2.pdf', 'application/pdf'),
            fakeFile('3.pdf', 'application/pdf'),
            fakeFile('4.pdf', 'application/pdf'),
        ]);

        expect(uploads.items.value.map((i) => i.name)).toEqual([
            '1.pdf',
            '2.pdf',
            '3.pdf',
        ]);
        expect(toastError).toHaveBeenCalledTimes(1);
    });
});
