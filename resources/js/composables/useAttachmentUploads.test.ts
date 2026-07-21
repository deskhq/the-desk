import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import type { EffectScope } from 'vue';

const { toastError } = vi.hoisted(() => ({ toastError: vi.fn() }));

vi.mock('vue-sonner', () => ({
    toast: { error: toastError, success: vi.fn() },
}));

import { useAttachmentUploads } from '@/composables/useAttachmentUploads';
import type { AttachmentUploads } from '@/composables/useAttachmentUploads';
import type { UploadFailure, UploadHandle } from '@/lib/uploadAttachment';

/** A file whose reported size we control without allocating its bytes. */
function fakeFile(name: string, type: string, sizeBytes = 10): File {
    const file = new File(['x'], name, { type });

    Object.defineProperty(file, 'size', { value: sizeBytes });

    return file;
}

/** A deferred a test can resolve/reject to drive one upload's outcome. */
interface Deferred {
    url: string;
    file: File;
    onProgress: (percent: number) => void;
    resolve: (value: unknown) => void;
    reject: (reason: UploadFailure) => void;
    abort: ReturnType<typeof vi.fn>;
}

/** A fake {@see Uploader} that records each call and hands back its controls. */
function fakeUploader() {
    const calls: Deferred[] = [];

    const uploader = (
        url: string,
        file: File,
        onProgress: (percent: number) => void,
    ): UploadHandle => {
        let resolve!: (value: unknown) => void;
        let reject!: (reason: UploadFailure) => void;
        const promise = new Promise((res, rej) => {
            resolve = res;
            reject = rej;
        });
        const abort = vi.fn();

        calls.push({ url, file, onProgress, resolve, reject, abort });

        return { promise: promise as Promise<never>, abort };
    };

    return { uploader, calls };
}

const MB = 1024 * 1024;

/** An attachment DTO shaped like the endpoint's 201 body. */
function attachment(id: string) {
    return {
        id,
        filename: 'f.png',
        mimeType: 'image/png',
        sizeBytes: 10,
        width: 1,
        height: 1,
        isImage: true,
        url: `/download/${id}`,
        thumbUrl: null,
    };
}

describe('useAttachmentUploads', () => {
    let scope: EffectScope;
    let uploads: AttachmentUploads;
    let calls: ReturnType<typeof fakeUploader>['calls'];
    let created: string[];
    let revoked: string[];

    function build() {
        const fake = fakeUploader();
        calls = fake.calls;
        created = [];
        revoked = [];

        scope = effectScope();
        scope.run(() => {
            uploads = useAttachmentUploads({
                endpoint: () => '/t/acme/c/design/attachments',
                maxSizeMb: () => 25,
                maxPerMessage: () => 3,
                uploader: fake.uploader,
                createObjectUrl: (file) => {
                    const url = `blob:${file.name}`;
                    created.push(url);

                    return url;
                },
                revokeObjectUrl: (url) => revoked.push(url),
            });
        });
    }

    beforeEach(() => {
        toastError.mockClear();
        build();
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

// The default object-URL hooks (used when the composer doesn't inject its own)
// bridge to the browser's `URL` API, which is absent in this Node test env — so
// each branch is exercised by toggling `URL.createObjectURL`/`revokeObjectURL`.
describe('useAttachmentUploads default preview hooks', () => {
    const urlApi = URL as unknown as {
        createObjectURL?: (file: File) => string;
        revokeObjectURL?: (url: string) => void;
    };
    let originalCreate: typeof urlApi.createObjectURL;
    let originalRevoke: typeof urlApi.revokeObjectURL;

    beforeEach(() => {
        originalCreate = urlApi.createObjectURL;
        originalRevoke = urlApi.revokeObjectURL;
    });

    afterEach(() => {
        urlApi.createObjectURL = originalCreate;
        urlApi.revokeObjectURL = originalRevoke;
    });

    function build() {
        const fake = fakeUploader();
        const scope = effectScope();
        let uploads!: AttachmentUploads;

        scope.run(() => {
            uploads = useAttachmentUploads({
                endpoint: () => '/e',
                maxSizeMb: () => 25,
                maxPerMessage: () => 3,
                uploader: fake.uploader,
                // No object-URL overrides: exercise the browser-API defaults.
            });
        });

        return { uploads, scope };
    }

    it('previews and revokes through the browser URL API when available', () => {
        const revoke = vi.fn();
        urlApi.createObjectURL = () => 'blob:real';
        urlApi.revokeObjectURL = revoke;

        const { uploads, scope } = build();
        uploads.addFiles([fakeFile('pic.png', 'image/png')]);

        expect(uploads.items.value[0].previewUrl).toBe('blob:real');

        uploads.remove(uploads.items.value[0].localId);
        expect(revoke).toHaveBeenCalledWith('blob:real');

        scope.stop();
    });

    it('falls back to no preview when the URL API is unavailable', () => {
        delete urlApi.createObjectURL;
        delete urlApi.revokeObjectURL;

        const { uploads, scope } = build();
        uploads.addFiles([fakeFile('pic.png', 'image/png')]);

        expect(uploads.items.value[0].previewUrl).toBeNull();

        // Removing a row with no preview must not throw despite the missing API.
        expect(() =>
            uploads.remove(uploads.items.value[0].localId),
        ).not.toThrow();

        scope.stop();
    });

    it('skips revoking when only createObjectURL is available', () => {
        urlApi.createObjectURL = () => 'blob:orphan';
        delete urlApi.revokeObjectURL;

        const { uploads, scope } = build();
        uploads.addFiles([fakeFile('pic.png', 'image/png')]);

        expect(uploads.items.value[0].previewUrl).toBe('blob:orphan');
        expect(() =>
            uploads.remove(uploads.items.value[0].localId),
        ).not.toThrow();

        scope.stop();
    });
});
