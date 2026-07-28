import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { EffectScope } from 'vue';

/**
 * Covers the snapshot the composer takes at send time: the tray empties
 * optimistically while its previews stay alive, a failed send restores the rows
 * in order ahead of anything staged since, a settled snapshot ignores a second
 * verdict, and teardown frees whatever a snapshot still owns.
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

describe('useAttachmentUploads detached snapshots', () => {
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

    /** Stage two finished rows the way the composer holds them at send time. */
    async function stageTwoDone() {
        uploads.addFiles([
            fakeFile('pic.png', 'image/png'),
            fakeFile('a.pdf', 'application/pdf'),
        ]);
        calls[0].resolve(attachment('att-1'));
        calls[1].resolve(attachment('att-2'));
        await Promise.resolve();
        await Promise.resolve();
    }

    it('detaches the tray into a restorable snapshot without revoking previews', async () => {
        await stageTwoDone();

        const snapshot = uploads.detach();

        // The tray is optimistically emptied for the in-flight send...
        expect(uploads.items.value).toHaveLength(0);
        expect(uploads.attachmentIds.value).toEqual([]);
        // ...but the previews are kept alive in case the send fails.
        expect(revoked).toEqual([]);

        snapshot.restore();

        // Restoring returns the rows in their original send order.
        expect(uploads.items.value.map((i) => i.name)).toEqual([
            'pic.png',
            'a.pdf',
        ]);
        expect(uploads.attachmentIds.value).toEqual(['att-1', 'att-2']);
    });

    it('disposes a detached snapshot by revoking its previews and leaves the tray empty', async () => {
        await stageTwoDone();

        const snapshot = uploads.detach();
        snapshot.dispose();

        expect(revoked).toContain('blob:pic.png');
        expect(uploads.items.value).toHaveLength(0);
    });

    it('re-registers a restored row so the tray can remove it again', async () => {
        await stageTwoDone();

        const snapshot = uploads.detach();
        snapshot.restore();
        uploads.remove(uploads.items.value[0].localId);

        expect(uploads.items.value.map((i) => i.name)).toEqual(['a.pdf']);
        // Removal only revokes when the row's source is back in place.
        expect(revoked).toContain('blob:pic.png');
    });

    it('restores a detached batch ahead of anything staged since the send', async () => {
        await stageTwoDone();

        const snapshot = uploads.detach();
        // The user starts a fresh message while the first send is in flight.
        uploads.addFiles([fakeFile('later.pdf', 'application/pdf')]);
        snapshot.restore();

        expect(uploads.items.value.map((i) => i.name)).toEqual([
            'pic.png',
            'a.pdf',
            'later.pdf',
        ]);
    });

    it('settles a snapshot once — a later restore or dispose is a no-op', async () => {
        await stageTwoDone();

        const snapshot = uploads.detach();
        snapshot.dispose();
        revoked.length = 0;
        snapshot.restore();

        // Already disposed: the tray stays empty and nothing is touched again.
        expect(uploads.items.value).toHaveLength(0);
        expect(revoked).toEqual([]);
    });

    it('releases an outstanding detached snapshot when the scope tears down', async () => {
        await stageTwoDone();

        uploads.detach(); // never restored or disposed

        scope.stop();

        // Teardown must free the previews the snapshot still owns.
        expect(revoked).toContain('blob:pic.png');
    });
});
