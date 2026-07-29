import { describe, expect, it } from 'vitest';
import type { PendingAttachment } from '@/composables/useAttachmentUploads';
import { totalsForUploadBatch } from '@/lib/uploadBatch';

/**
 * Covers the one line a progress toast has to fill: how many files, how many
 * bytes, and the single percent standing for all of them.
 */
const MB = 1024 * 1024;

/** A staged row with only the fields the totals read. */
function row(
    overrides: Partial<PendingAttachment> & { sizeBytes: number },
): PendingAttachment {
    return {
        localId: `row-${overrides.sizeBytes}-${overrides.progress ?? 0}`,
        name: 'file.bin',
        isImage: false,
        isAudio: false,
        previewUrl: null,
        status: 'uploading',
        progress: 0,
        attachment: null,
        ...overrides,
    };
}

describe('totalsForUploadBatch', () => {
    it('counts the files and their total size, not the bytes sent so far', () => {
        const totals = totalsForUploadBatch([
            row({ sizeBytes: 10 * MB, progress: 50 }),
            row({ sizeBytes: 2 * MB, progress: 0 }),
        ]);

        expect(totals.fileCount).toBe(2);
        expect(totals.totalBytes).toBe(12 * MB);
    });

    it('weighs the percent by size rather than averaging the rows', () => {
        // The big file is barely started, the small one is done: a mean of the
        // row percentages would claim 55%, which is not the work remaining.
        const totals = totalsForUploadBatch([
            row({ sizeBytes: 40 * MB, progress: 10 }),
            row({ sizeBytes: 12, status: 'done', progress: 100 }),
        ]);

        expect(totals.percent).toBe(10);
    });

    it('keeps a finished row in the denominator at 100 so the figure only climbs', () => {
        const rows = [
            row({ sizeBytes: MB, status: 'done', progress: 100 }),
            row({ sizeBytes: MB, progress: 40 }),
        ];

        expect(totalsForUploadBatch(rows).percent).toBe(70);
    });

    it('holds a failed row at the percent it reached, stalling short of 100', () => {
        const totals = totalsForUploadBatch([
            row({ sizeBytes: MB, status: 'done', progress: 100 }),
            row({ sizeBytes: MB, status: 'failed', progress: 30 }),
        ]);

        expect(totals.percent).toBe(65);
        expect(totals.isUploading).toBe(false);
        expect(totals.failed).toHaveLength(1);
    });

    it('never claims a hundred while a byte is still in flight', () => {
        const totals = totalsForUploadBatch([
            row({ sizeBytes: 1000 * MB, progress: 100 }),
            row({ sizeBytes: MB, progress: 0 }),
        ]);

        expect(totals.percent).toBe(99);
    });

    it('holds at 99 while a fully-sent row waits for its answer', () => {
        // `xhr.upload` reports 100 the moment the last byte leaves, but the row
        // is only `done` once the server hands back the stored attachment — a
        // card reading "Uploading 1 file · 100%" would be claiming otherwise.
        const totals = totalsForUploadBatch([
            row({ sizeBytes: MB, progress: 100 }),
        ]);

        expect(totals.percent).toBe(99);
        expect(totals.isUploading).toBe(true);
    });

    it('averages the rows when the batch carries no bytes at all', () => {
        const totals = totalsForUploadBatch([
            row({ sizeBytes: 0, status: 'done', progress: 100 }),
            row({ sizeBytes: 0, progress: 0 }),
        ]);

        expect(totals.percent).toBe(50);
    });

    it('reports an empty batch as untouched and settled', () => {
        const totals = totalsForUploadBatch([]);

        expect(totals).toEqual({
            fileCount: 0,
            totalBytes: 0,
            percent: 0,
            isUploading: false,
            failed: [],
        });
    });
});
