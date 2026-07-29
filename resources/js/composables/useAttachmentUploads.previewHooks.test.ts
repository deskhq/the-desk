import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';

/**
 * The default object-URL hooks (used when the composer doesn't inject its own)
 * bridge to the browser's `URL` API, which is absent in this Node test env — so
 * each branch is exercised by toggling `URL.createObjectURL`/`revokeObjectURL`.
 */
vi.mock('@/composables/useToast', () => {
    const toast = {
        error: vi.fn(),
        success: vi.fn(),
        warning: vi.fn(),
        progress: vi.fn(),
    };

    return { useToast: () => toast };
});

import { useAttachmentUploads } from '@/composables/useAttachmentUploads';
import type { AttachmentUploads } from '@/composables/useAttachmentUploads';
import { fakeFile, fakeUploader } from './useAttachmentUploads.doubles';

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
