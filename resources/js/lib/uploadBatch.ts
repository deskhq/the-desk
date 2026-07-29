import type { PendingAttachment } from '@/composables/useAttachmentUploads';

/** What one batch of staged uploads reports while it runs and when it lands. */
export interface UploadBatchTotals {
    /** How many rows the batch holds. */
    fileCount: number;
    /**
     * The batch's total size in bytes — what the card names, rather than
     * bytes-so-far, which would put two numbers on one line racing each other.
     */
    totalBytes: number;
    /** Byte-weighted progress across the batch, a whole percent from 0 to 100. */
    percent: number;
    /** Whether any row is still transferring. */
    isUploading: boolean;
    /** The rows whose transport failed, in batch order. */
    failed: PendingAttachment[];
}

/** How far one row has got, with a finished row pinned at 100. */
function progressOf(row: PendingAttachment): number {
    return row.status === 'done' ? 100 : row.progress;
}

/**
 * Total up a batch of staged uploads for the one line a progress toast has.
 *
 * The percent is weighted by size rather than averaged across rows: a 40 MB
 * video and a 12 KB screenshot are not half the work each. Every row the batch
 * started with stays in the denominator whatever became of it — a finished one
 * at 100, a failed one frozen at the percent it reached — so the figure only
 * ever climbs, and a failure is what stalls it short of 100 rather than
 * something that quietly leaves and drags the number backwards with it.
 */
export function totalsForUploadBatch(
    rows: PendingAttachment[],
): UploadBatchTotals {
    const totalBytes = rows.reduce((total, row) => total + row.sizeBytes, 0);
    const isUploading = rows.some((row) => row.status === 'uploading');

    // 100% has to mean finished. Floored rather than rounded, so a batch at
    // 99.6% does not claim it — and held at 99 for as long as anything is still
    // in flight, because `xhr.upload` reports 100 the moment the last byte is
    // sent, with the server's answer (and the stored id) still to come.
    const ceiling = isUploading ? 99 : 100;

    return {
        fileCount: rows.length,
        totalBytes,
        percent: Math.max(
            0,
            Math.min(ceiling, Math.floor(weigh(rows, totalBytes))),
        ),
        isUploading,
        failed: rows.filter((row) => row.status === 'failed'),
    };
}

/** The batch's progress, weighted by each row's share of the bytes. */
function weigh(rows: PendingAttachment[], totalBytes: number): number {
    if (rows.length === 0) {
        return 0;
    }

    // Zero-byte rows carry no weight to distribute, so the rows themselves are
    // the only unit left to average over.
    if (totalBytes === 0) {
        return (
            rows.reduce((total, row) => total + progressOf(row), 0) /
            rows.length
        );
    }

    return (
        rows.reduce(
            (total, row) => total + row.sizeBytes * progressOf(row),
            0,
        ) / totalBytes
    );
}
