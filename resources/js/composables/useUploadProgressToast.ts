import { watchEffect } from 'vue';
import type {
    AttachmentUploads,
    PendingAttachment,
} from '@/composables/useAttachmentUploads';
import type { ChannelUploads } from '@/composables/useChannelUploads';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import { formatFileSize } from '@/lib/attachments';
import { totalsForUploadBatch } from '@/lib/uploadBatch';

/**
 * The one key every staged upload reports under. Two channels uploading at once
 * are one activity, not two events: the stack is for unrelated things.
 */
const MERGE_KEY = 'attachment-uploads';

/**
 * Roughly one redraw a second. Progress events arrive per chunk per file, so
 * three files unthrottled are a redraw storm for a figure that only ever reads
 * to a whole percent.
 */
const TICK_MS = 1_000;

export interface UploadProgressToastOptions {
    /** Every channel holding a tray right now, and the tray itself. */
    channels: () => ChannelUploads[];
    /**
     * The channel whose composer is on screen, or null anywhere else — the
     * settings pages, the workspace index, a thread with no channel behind it.
     */
    activeChannelKey: () => string | null;
    /** A channel's display name, where the sidebar knows one. */
    channelName: (channelKey: string) => string | null;
    /** Take the user to a channel: the View action on a landed batch. */
    openChannel: (channelKey: string) => void;
    /** The clock, injected so the throttle needs no timers to test. */
    now?: () => number;
}

/** One staged row, and the channel whose tray it is sitting in. */
interface TrackedRow {
    channelKey: string;
    uploads: AttachmentUploads;
    row: PendingAttachment;
}

/**
 * Report staged uploads the user has walked away from, in a single toast.
 *
 * An upload's inline surface is the tray in its channel's composer, so it is
 * worth a toast exactly when that composer is not the one on screen. The card
 * therefore appears when the last visible surface goes away rather than when
 * the upload starts — upload in #design and stay there and no toast ever fires
 * — and coming back dismisses it rather than resolving it, because the tray has
 * taken the job back.
 *
 * What it reports is a *batch*, fixed at the moment the card appears: the rows
 * uploading right then, joined by any that start later. Summing whatever
 * happens to be uploading at each instant instead would drop a row from the set
 * the moment it landed, and the percentage would fall backwards — worse than no
 * percentage at all. {@see totalsForUploadBatch} keeps the arithmetic honest;
 * this owns which rows it runs over and when the card is worth firing.
 */
export function useUploadProgressToast(
    options: UploadProgressToastOptions,
): void {
    const { t } = useTranslations();
    const toast = useToast();
    const clock = options.now ?? (() => Date.now());

    /** The local ids the card is reporting on, in the order they joined. */
    let batch: string[] = [];

    /** Whether a progress card of ours is on screen. */
    let showing = false;

    let lastFiredAt = 0;
    let lastSignature = '';

    function forget(): void {
        batch = [];
        showing = false;
        lastSignature = '';
    }

    /** Every staged row in the workspace, whichever channel is holding it. */
    function stagedRows(): TrackedRow[] {
        return options.channels().flatMap(({ channelKey, uploads }) =>
            uploads.items.value.map((row) => ({
                channelKey,
                uploads,
                row,
            })),
        );
    }

    /**
     * Re-fire the progress card under the same key, which replaces it in place.
     * Throttled: only a change in the count or the whole percent is worth a
     * redraw, and then at most once a tick.
     */
    function reportProgress(rows: PendingAttachment[], force: boolean): void {
        const totals = totalsForUploadBatch(rows);
        const signature = `${totals.fileCount}:${totals.percent}`;
        const now = clock();

        if (
            !force &&
            (signature === lastSignature || now - lastFiredAt < TICK_MS)
        ) {
            return;
        }

        lastSignature = signature;
        lastFiredAt = now;

        toast.progress(
            totals.fileCount === 1
                ? t('Uploading 1 file')
                : t('Uploading :count files', { count: totals.fileCount }),
            {
                key: MERGE_KEY,
                meta: formatFileSize(totals.totalBytes),
                value: `${totals.percent}%`,
            },
        );
    }

    /** Swap the progress card in place for what became of the batch. */
    function resolve(members: TrackedRow[]): void {
        const totals = totalsForUploadBatch(
            members.map((member) => member.row),
        );

        if (totals.failed.length > 0) {
            reportFailure(members, totals.failed);
        } else {
            reportReady(members, totals.fileCount);
        }

        forget();
    }

    /**
     * Name only what failed, and offer the retry. Safe to treat everything here
     * as retryable: a definitive rejection (a bad type, an over-size file) is
     * removed from the tray with its own toast the moment it lands, so a row
     * still `failed` at this point failed in transport.
     */
    function reportFailure(
        members: TrackedRow[],
        failed: PendingAttachment[],
    ): void {
        const ids = new Set(failed.map((row) => row.localId));
        const retryable = members.filter((member) =>
            ids.has(member.row.localId),
        );

        toast.error(
            failed.length === 1
                ? t("1 file didn't upload")
                : t(":count files didn't upload", { count: failed.length }),
            {
                key: MERGE_KEY,
                action: {
                    label: t('Retry'),
                    run: () => {
                        for (const member of retryable) {
                            member.uploads.retry(member.row.localId);
                        }
                    },
                },
            },
        );
    }

    /**
     * Confirm the batch — carefully. Nothing has been posted: the files are
     * staged in a tray, waiting for a send. "3 files uploaded" would read as
     * "your files went to the channel" to someone who was on another page when
     * it fired.
     */
    function reportReady(members: TrackedRow[], fileCount: number): void {
        const channelKeys = new Set(members.map((member) => member.channelKey));
        const [only] = [...channelKeys];
        const single = channelKeys.size === 1 ? only : null;

        toast.success(
            fileCount === 1
                ? t('1 file ready to send')
                : t(':count files ready to send', { count: fileCount }),
            {
                key: MERGE_KEY,
                // A batch spanning channels has no one channel to name or to
                // open, and picking a winner would be a lie either way.
                meta: single
                    ? (options.channelName(single) ?? undefined)
                    : undefined,
                action: single
                    ? {
                          label: t('View'),
                          run: () => options.openChannel(single),
                      }
                    : undefined,
            },
        );
    }

    watchEffect(() => {
        const active = options.activeChannelKey();
        const staged = stagedRows();
        const away = staged.filter((member) => member.channelKey !== active);

        if (!showing) {
            const starting = away.filter(
                (member) => member.row.status === 'uploading',
            );

            // Rows that were already finished when the user walked away are not
            // work in flight, so they never join a batch: leaving a channel
            // where two files landed and a third is still going says
            // "Uploading 1 file".
            if (starting.length === 0) {
                return;
            }

            batch = starting.map((member) => member.row.localId);
            showing = true;
            reportProgress(
                starting.map((member) => member.row),
                true,
            );

            return;
        }

        for (const member of away) {
            if (
                member.row.status === 'uploading' &&
                !batch.includes(member.row.localId)
            ) {
                batch.push(member.row.localId);
            }
        }

        const staying = new Map(
            staged.map((member) => [member.row.localId, member]),
        );
        const members = batch.flatMap((localId) => {
            const member = staying.get(localId);

            return member ? [member] : [];
        });

        // Every row is back in front of the user, or gone from the tray
        // altogether: the inline surface has the job back, so the card is taken
        // away rather than resolved. A resolution the user is standing in front
        // of would only repeat what the tray already says.
        if (members.every((member) => member.channelKey === active)) {
            toast.dismiss(MERGE_KEY);
            forget();

            return;
        }

        const totals = totalsForUploadBatch(
            members.map((member) => member.row),
        );

        if (totals.isUploading) {
            reportProgress(
                members.map((member) => member.row),
                false,
            );

            return;
        }

        resolve(members);
    });
}
