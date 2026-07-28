import { vi } from 'vitest';
import { effectScope } from 'vue';
import type { EffectScope } from 'vue';
import { useAttachmentUploads } from '@/composables/useAttachmentUploads';
import type { AttachmentUploads } from '@/composables/useAttachmentUploads';
import type { UploadFailure, UploadHandle } from '@/lib/uploadAttachment';

/**
 * The harness the upload-tray suites share: the file and attachment factories,
 * the uploader stand-in whose outcome each test drives by hand, and the tray
 * itself built in its own effect scope with its object-URL hooks recorded.
 *
 * The toast boundary is deliberately *not* here. Every suite mocks it through
 * `vi.hoisted`, because a mock factory reaching into this module would import
 * the composable under test, which imports `useToast` — the very module the
 * factory is still resolving, and the run deadlocks there.
 */

/** A file whose reported size we control without allocating its bytes. */
export function fakeFile(name: string, type: string, sizeBytes = 10): File {
    const file = new File(['x'], name, { type });

    Object.defineProperty(file, 'size', { value: sizeBytes });

    return file;
}

/** A deferred a test can resolve/reject to drive one upload's outcome. */
export interface Deferred {
    url: string;
    file: File;
    onProgress: (percent: number) => void;
    resolve: (value: unknown) => void;
    reject: (reason: UploadFailure) => void;
    abort: ReturnType<typeof vi.fn>;
}

/** A fake {@see Uploader} that records each call and hands back its controls. */
export function fakeUploader() {
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

export const MB = 1024 * 1024;

/** An attachment DTO shaped like the endpoint's 201 body. */
export function attachment(id: string) {
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

/** What one built tray hands its suite: the tray, its scope and its recorders. */
export interface UploadsHarness {
    scope: EffectScope;
    uploads: AttachmentUploads;
    calls: Deferred[];
    created: string[];
    revoked: string[];
}

/** A tray wired to a fake uploader and to object-URL hooks that record. */
export function buildUploads(): UploadsHarness {
    const fake = fakeUploader();
    const created: string[] = [];
    const revoked: string[] = [];
    let uploads!: AttachmentUploads;

    const scope = effectScope();
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

    return { scope, uploads, calls: fake.calls, created, revoked };
}
