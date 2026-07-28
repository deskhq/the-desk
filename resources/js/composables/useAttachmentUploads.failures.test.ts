import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { EffectScope } from 'vue';

/**
 * Covers where a staged upload ends up once the endpoint answers, and how the
 * tray is unwound: which rejection is a toast and which is a retryable row,
 * what a retry re-fires, and what removing or clearing a row aborts and
 * revokes. What lands in the tray in the first place is pinned in
 * `useAttachmentUploads.test`.
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
    attachment,
    buildUploads,
    fakeFile,
} from './useAttachmentUploads.doubles';
import type { Deferred } from './useAttachmentUploads.doubles';

describe('useAttachmentUploads failures and removal', () => {
    let scope: EffectScope;
    let uploads: AttachmentUploads;
    let calls: Deferred[];
    let revoked: string[];

    beforeEach(() => {
        toastError.mockClear();
        ({ scope, uploads, calls, revoked } = buildUploads());
    });

    afterEach(() => {
        scope.stop();
    });

    it('surfaces a server validation rejection as a toast and drops the row', async () => {
        uploads.addFiles([fakeFile('evil.php', 'application/x-php')]);
        calls[0].reject({
            status: 422,
            message: "This file type isn't allowed.",
            aborted: false,
        });
        await Promise.resolve();
        await Promise.resolve();

        expect(uploads.items.value).toHaveLength(0);
        expect(toastError).toHaveBeenCalledWith(
            "This file type isn't allowed.",
        );
    });

    it('keeps a generic transport failure as a retryable failed row without a toast', async () => {
        uploads.addFiles([fakeFile('a.pdf', 'application/pdf')]);
        calls[0].reject({ status: 0, message: null, aborted: false });
        await Promise.resolve();
        await Promise.resolve();

        expect(uploads.items.value[0].status).toBe('failed');
        expect(uploads.hasFailed.value).toBe(true);
        expect(toastError).not.toHaveBeenCalled();
    });

    it('retries a failed row through a fresh upload', async () => {
        uploads.addFiles([fakeFile('a.pdf', 'application/pdf')]);
        calls[0].reject({ status: 500, message: null, aborted: false });
        await Promise.resolve();
        await Promise.resolve();

        uploads.retry(uploads.items.value[0].localId);

        expect(uploads.items.value[0].status).toBe('uploading');
        expect(calls).toHaveLength(2);

        calls[1].resolve(attachment('att-9'));
        await Promise.resolve();
        await Promise.resolve();

        expect(uploads.items.value[0].status).toBe('done');
        expect(uploads.attachmentIds.value).toEqual(['att-9']);
    });

    it('ignores a retry on a row that is not in a failed state', () => {
        uploads.addFiles([fakeFile('a.pdf', 'application/pdf')]);

        // Still uploading — retry must not fire a second upload.
        uploads.retry(uploads.items.value[0].localId);
        expect(calls).toHaveLength(1);

        calls[0].resolve(attachment('att-1'));

        return Promise.resolve()
            .then(() => Promise.resolve())
            .then(() => {
                // Now done — retry is likewise a no-op.
                expect(uploads.items.value[0].status).toBe('done');
                uploads.retry(uploads.items.value[0].localId);
                expect(calls).toHaveLength(1);
            });
    });

    it('removes a row, aborting an in-flight upload and revoking its preview', () => {
        uploads.addFiles([fakeFile('pic.png', 'image/png')]);
        const { localId } = uploads.items.value[0];

        uploads.remove(localId);

        expect(uploads.items.value).toHaveLength(0);
        expect(calls[0].abort).toHaveBeenCalled();
        expect(revoked).toContain('blob:pic.png');
    });

    it('an aborted upload never resurrects the removed row', async () => {
        uploads.addFiles([fakeFile('pic.png', 'image/png')]);
        const { localId } = uploads.items.value[0];
        uploads.remove(localId);

        calls[0].reject({ status: 0, message: null, aborted: true });
        await Promise.resolve();
        await Promise.resolve();

        expect(uploads.items.value).toHaveLength(0);
    });

    it('clears the whole tray, aborting uploads and revoking previews', () => {
        uploads.addFiles([
            fakeFile('pic.png', 'image/png'),
            fakeFile('a.pdf', 'application/pdf'),
        ]);

        uploads.clear();

        expect(uploads.items.value).toHaveLength(0);
        expect(uploads.attachmentIds.value).toEqual([]);
        expect(calls[0].abort).toHaveBeenCalled();
        expect(revoked).toContain('blob:pic.png');
    });
});
