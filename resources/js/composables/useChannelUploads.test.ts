import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Covers the lifetime the channel registry gives a tray: which composer shares
 * which rows, what survives a composer going away, and when an entry is finally
 * released. The tray's own behaviour — staging, progress, failures, snapshots —
 * is pinned by the `useAttachmentUploads` suites; what is watched here is only
 * who owns it and for how long.
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

import { releaseChannelUploads } from '@/composables/useChannelUploads';
import {
    attachment,
    buildRegistry,
    fakeFile,
} from './useAttachmentUploads.doubles';
import type { RegistryHarness } from './useAttachmentUploads.doubles';

describe('useChannelUploads', () => {
    let registry: RegistryHarness;

    beforeEach(() => {
        toastError.mockClear();
        registry = buildRegistry();
    });

    afterEach(() => {
        registry.closeAll();
        releaseChannelUploads();
    });

    /** Settle an upload's `.then` handlers. */
    async function settle(): Promise<void> {
        await Promise.resolve();
        await Promise.resolve();
    }

    it('hands both composers of a channel the same tray', () => {
        const first = registry.open('acme/design');
        const second = registry.open('acme/design');

        expect(second.uploads).toBe(first.uploads);
    });

    it('keeps a separate tray per channel', () => {
        const design = registry.open('acme/design');
        const general = registry.open('acme/general');

        design.uploads.addFiles([fakeFile('a.pdf', 'application/pdf')]);

        expect(general.uploads.items.value).toHaveLength(0);
    });

    it('keeps an upload running when the composer that started it goes away', () => {
        const design = registry.open('acme/design');
        design.uploads.addFiles([fakeFile('a.pdf', 'application/pdf')]);

        design.close();

        // Leaving the channel must neither abort the transfer nor drop the row.
        expect(registry.calls[0].abort).not.toHaveBeenCalled();
        expect(design.uploads.items.value).toHaveLength(1);
    });

    it('shows the tray as it was, finished rows included, on returning', async () => {
        const away = registry.open('acme/design');
        away.uploads.addFiles([fakeFile('pic.png', 'image/png')]);
        away.close();

        registry.calls[0].resolve(attachment('att-1'));
        await settle();

        const back = registry.open('acme/design');

        expect(back.uploads).toBe(away.uploads);
        expect(back.uploads.items.value.map((row) => row.name)).toEqual([
            'pic.png',
        ]);
        // Still claimable by the send the user came back to make.
        expect(back.uploads.attachmentIds.value).toEqual(['att-1']);
        expect(registry.revoked).toEqual([]);
    });

    it('drops an empty channel’s entry once its composer goes away', () => {
        const first = registry.open('acme/design');
        first.close();

        // Nothing was staged, so nothing is worth keeping: a later visit starts
        // from a fresh tray rather than an ever-growing registry of empties.
        expect(registry.open('acme/design').uploads).not.toBe(first.uploads);
    });

    it('releases an entry and its previews once its last row leaves while away', async () => {
        const away = registry.open('acme/design');
        away.uploads.addFiles([fakeFile('pic.png', 'image/png')]);
        away.close();

        // The server rejects the file type: the row is dropped where it stands,
        // emptying a tray nobody is watching.
        registry.calls[0].reject({
            status: 422,
            message: 'This file type is not allowed.',
            aborted: false,
        });
        await settle();

        expect(registry.revoked).toContain('blob:pic.png');
        expect(registry.open('acme/design').uploads).not.toBe(away.uploads);
    });

    it('holds an entry open while a send snapshot is still outstanding', async () => {
        const sending = registry.open('acme/design');
        sending.uploads.addFiles([fakeFile('pic.png', 'image/png')]);
        registry.calls[0].resolve(attachment('att-1'));
        await settle();

        // The send empties the tray optimistically, then fails while the user is
        // already in another channel — the rows must still have somewhere to go.
        const snapshot = sending.uploads.detach();
        sending.close();

        const back = registry.open('acme/design');
        expect(back.uploads).toBe(sending.uploads);

        snapshot.restore();
        expect(back.uploads.items.value.map((row) => row.name)).toEqual([
            'pic.png',
        ]);
    });

    it('keeps uploading to the endpoint the channel was opened with', () => {
        const first = registry.open('acme/design', {
            endpoint: '/t/acme/c/design/attachments',
        });
        first.uploads.addFiles([fakeFile('a.pdf', 'application/pdf')]);
        first.close();

        // A second composer re-adopts the entry rather than rebuilding it, so
        // the rows it inherits keep the endpoint their transfers already use.
        const back = registry.open('acme/design', {
            endpoint: '/t/acme/c/other/attachments',
        });
        back.uploads.addFiles([fakeFile('b.pdf', 'application/pdf')]);

        expect(registry.calls.map((call) => call.url)).toEqual([
            '/t/acme/c/design/attachments',
            '/t/acme/c/design/attachments',
        ]);
    });

    it('drops every channel and its previews on a team switch', () => {
        const design = registry.open('acme/design');
        design.uploads.addFiles([fakeFile('pic.png', 'image/png')]);
        const general = registry.open('acme/general');
        general.uploads.addFiles([fakeFile('shot.png', 'image/png')]);

        releaseChannelUploads();

        expect(registry.revoked).toEqual(['blob:pic.png', 'blob:shot.png']);
        expect(registry.calls[0].abort).toHaveBeenCalled();
        expect(registry.open('acme/design').uploads).not.toBe(design.uploads);
    });

    it('gives a composer with no channel an unshared tray it owns outright', () => {
        const thread = registry.open(null);
        const other = registry.open(null);

        expect(other.uploads).not.toBe(thread.uploads);

        thread.uploads.addFiles([fakeFile('pic.png', 'image/png')]);
        thread.close();

        // Nothing to outlive: an unregistered tray dies with its composer.
        expect(thread.uploads.items.value).toHaveLength(0);
        expect(registry.revoked).toContain('blob:pic.png');
    });
});
