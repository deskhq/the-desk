import { computed, onScopeDispose, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { toast } from 'vue-sonner';
import { useTranslations } from '@/composables/useTranslations';
import { formatFileSize, isImageMime } from '@/lib/attachments';
import { xhrUpload } from '@/lib/uploadAttachment';
import type { Uploader } from '@/lib/uploadAttachment';
import { generateUuid } from '@/lib/uuid';
import type { AttachmentData } from '@/types/attachments';

/** The lifecycle state of one row in the composer's pre-send attachment tray. */
export type AttachmentUploadStatus = 'uploading' | 'done' | 'failed';

/**
 * A single file staged in the composer tray. It uploads immediately (two-phase);
 * `attachment` carries the stored DTO — and its claimable `id` — once `status`
 * reaches `done`.
 */
export interface PendingAttachment {
    /** A client-side key, stable across the row's lifetime (upload → retry). */
    localId: string;
    /** The original filename, shown in the tray chip. */
    name: string;
    /** The byte size, for the "· 2.1 MB" label. */
    sizeBytes: number;
    /** Whether to preview inline as an image (false for SVG and non-images). */
    isImage: boolean;
    /** An object URL for the local image preview, or null for a non-image. */
    previewUrl: string | null;
    /** Where the upload is in its lifecycle. */
    status: AttachmentUploadStatus;
    /** Upload progress, 0–100, meaningful while `status` is `uploading`. */
    progress: number;
    /** The stored attachment once `status` is `done`, else null. */
    attachment: AttachmentData | null;
}

export interface AttachmentUploadsOptions {
    /** The channel-scoped upload endpoint, re-read per upload so it stays current. */
    endpoint: () => string;
    /** The per-file size cap, in megabytes (mirrors the server config). */
    maxSizeMb: () => number;
    /** The per-message file-count cap (mirrors the server config). */
    maxPerMessage: () => number;
    /** The upload transport; defaults to the real XHR uploader, faked in tests. */
    uploader?: Uploader;
    /** Create a local preview URL for an image file; injectable for tests. */
    createObjectUrl?: (file: File) => string | null;
    /** Release a preview URL created above; injectable for tests. */
    revokeObjectUrl?: (url: string) => void;
}

/**
 * A detached tray, held while an optimistic send is in flight. The rows have
 * left the live tray (so a fresh message starts clean) but keep their previews
 * and stored ids, so the send's outcome can either {@see dispose} them (it stuck)
 * or {@see restore} them (it failed and the user must retry).
 */
export interface AttachmentSnapshot {
    /** Return the snapshot's rows to the tray, ahead of anything staged since. */
    restore: () => void;
    /** Drop the snapshot for good, releasing its preview URLs. */
    dispose: () => void;
}

export interface AttachmentUploads {
    /** The tray rows, in send (`attachment_ids[]`) order. */
    items: Ref<PendingAttachment[]>;
    /** How many rows are staged. */
    count: ComputedRef<number>;
    /** Whether any row is still uploading (blocks send). */
    isUploading: ComputedRef<boolean>;
    /** Whether any row failed and still needs a retry or removal (blocks send). */
    hasFailed: ComputedRef<boolean>;
    /** The claimable ids of the finished uploads, in tray order. */
    attachmentIds: ComputedRef<string[]>;
    /** Stage and begin uploading the given files, validating size and count. */
    addFiles: (files: Iterable<File>) => void;
    /**
     * Stage an already-created remote attachment (a picked Giphy GIF): it is
     * "done" the moment it lands — the server created the pending row — so it
     * joins the tray with its claimable id, contributing to `attachmentIds`.
     * Honours the per-message count cap; returns whether it was accepted (false
     * when the tray is already full).
     */
    addRemote: (attachment: AttachmentData) => boolean;
    /** Remove a row, cancelling its upload and releasing any preview. */
    remove: (localId: string) => void;
    /** Re-upload a previously failed row. */
    retry: (localId: string) => void;
    /**
     * Detach the tray for an in-flight send: the rows leave the live tray but
     * survive in the returned snapshot, to be restored if the send fails or
     * disposed once it sticks.
     */
    detach: () => AttachmentSnapshot;
    /** Drop every row (called once a send has claimed them). */
    clear: () => void;
}

/** Create an object URL for a preview where the browser API is available. */
function defaultCreateObjectUrl(file: File): string | null {
    if (
        typeof URL !== 'undefined' &&
        typeof URL.createObjectURL === 'function'
    ) {
        return URL.createObjectURL(file);
    }

    return null;
}

/** Release an object URL where the browser API is available. */
function defaultRevokeObjectUrl(url: string): void {
    if (
        typeof URL !== 'undefined' &&
        typeof URL.revokeObjectURL === 'function'
    ) {
        URL.revokeObjectURL(url);
    }
}

/**
 * Own the composer's pre-send attachment tray: files upload the moment they are
 * dropped, pasted, or picked (the two-phase flow), each row tracking its own
 * progress, so the send later just claims the finished ids. Size and count are
 * pre-checked here for instant feedback; the server re-enforces both as the
 * source of truth. Pure and Vue-reactive with an injectable uploader, so the
 * whole lifecycle unit-tests without a real network or DOM.
 */
export function useAttachmentUploads(
    options: AttachmentUploadsOptions,
): AttachmentUploads {
    const { t } = useTranslations();
    const upload = options.uploader ?? xhrUpload;
    const createObjectUrl = options.createObjectUrl ?? defaultCreateObjectUrl;
    const revokeObjectUrl = options.revokeObjectUrl ?? defaultRevokeObjectUrl;

    const items = ref<PendingAttachment[]>([]);

    // The source File and the live upload's abort handle, kept off the reactive
    // rows (Files aren't reactive, and an abort fn shouldn't be proxied).
    const sources = new Map<string, { file: File; abort: () => void }>();

    // Detached snapshots awaiting a send outcome. Their rows have left `items`
    // and `sources`, so a scope teardown mid-send would otherwise leak their
    // preview URLs; disposing them on teardown keeps the no-leak guarantee.
    const outstanding = new Set<() => void>();

    const count = computed(() => items.value.length);
    const isUploading = computed(() =>
        items.value.some((item) => item.status === 'uploading'),
    );
    const hasFailed = computed(() =>
        items.value.some((item) => item.status === 'failed'),
    );
    const attachmentIds = computed(() =>
        items.value.flatMap((item) =>
            item.attachment ? [item.attachment.id] : [],
        ),
    );

    /** The current row for a local id, or undefined once it has been removed. */
    function find(localId: string): PendingAttachment | undefined {
        return items.value.find((item) => item.localId === localId);
    }

    /** Fire the upload for a row's stored file, wiring progress and outcome. */
    function startUpload(localId: string): void {
        const source = sources.get(localId);

        if (!source) {
            return;
        }

        const handle = upload(options.endpoint(), source.file, (percent) => {
            const row = find(localId);

            if (row) {
                row.progress = percent;
            }
        });

        sources.set(localId, { file: source.file, abort: handle.abort });

        handle.promise.then(
            (attachment) => {
                const row = find(localId);

                if (!row) {
                    return;
                }

                row.status = 'done';
                row.progress = 100;
                row.attachment = attachment;
            },
            (failure) => {
                // A removed row was aborted deliberately — leave it gone.
                if (failure.aborted || !find(localId)) {
                    return;
                }

                // A validation rejection (type/size) is definitive: surface it
                // as a toast and drop the row — a retry would only fail again.
                // A generic transport failure stays as a retryable failed row.
                if (failure.message !== null || failure.status === 422) {
                    toast.error(
                        failure.message ?? t('This file type is not allowed.'),
                    );
                    remove(localId);

                    return;
                }

                const row = find(localId);

                if (row) {
                    row.status = 'failed';
                }
            },
        );
    }

    function addFiles(files: Iterable<File>): void {
        let queue = Array.from(files);

        if (queue.length === 0) {
            return;
        }

        // Count cap: keep the first files that fit under the per-message limit.
        const remaining = options.maxPerMessage() - items.value.length;

        if (queue.length > remaining) {
            queue = queue.slice(0, Math.max(remaining, 0));
            toast.error(
                t('You can attach up to :max files per message.', {
                    max: options.maxPerMessage(),
                }),
            );
        }

        const maxBytes = options.maxSizeMb() * 1024 * 1024;

        for (const file of queue) {
            if (file.size > maxBytes) {
                toast.error(
                    t(
                        ':name is too large (:size). Files can be up to :max MB.',
                        {
                            name: file.name,
                            size: formatFileSize(file.size),
                            max: options.maxSizeMb(),
                        },
                    ),
                );

                continue;
            }

            const localId = generateUuid();
            const isImage = isImageMime(file.type);

            items.value.push({
                localId,
                name: file.name,
                sizeBytes: file.size,
                isImage,
                previewUrl: isImage ? createObjectUrl(file) : null,
                status: 'uploading',
                progress: 0,
                attachment: null,
            });
            sources.set(localId, { file, abort: () => {} });
            startUpload(localId);
        }
    }

    function addRemote(attachment: AttachmentData): boolean {
        // A remote attachment carries no File and no local blob preview: it is
        // hotlinked, so the tray previews it (and later renders it) straight from
        // the CDN url. It has no upload to run — it arrives already `done`.
        if (items.value.length >= options.maxPerMessage()) {
            toast.error(
                t('You can attach up to :max files per message.', {
                    max: options.maxPerMessage(),
                }),
            );

            return false;
        }

        items.value.push({
            localId: generateUuid(),
            name: attachment.description ?? t('GIF'),
            sizeBytes: attachment.sizeBytes,
            isImage: attachment.isImage,
            previewUrl: attachment.thumbUrl ?? attachment.url,
            status: 'done',
            progress: 100,
            attachment,
        });

        return true;
    }

    function remove(localId: string): void {
        const source = sources.get(localId);
        source?.abort();
        sources.delete(localId);

        const row = find(localId);

        if (row?.previewUrl) {
            revokeObjectUrl(row.previewUrl);
        }

        items.value = items.value.filter((item) => item.localId !== localId);
    }

    function retry(localId: string): void {
        const row = find(localId);

        // Only a failed row is retryable — never re-fire an in-flight or already
        // finished upload.
        if (!row || row.status !== 'failed' || !sources.has(localId)) {
            return;
        }

        row.status = 'uploading';
        row.progress = 0;
        startUpload(localId);
    }

    function detach(): AttachmentSnapshot {
        // A send is blocked while any row is uploading or failed, so every
        // detached row is already `done` with its upload settled — capturing
        // the file/abort source is enough to hand ownership to the snapshot.
        const captured = items.value.map((row) => ({
            row,
            source: sources.get(row.localId),
        }));

        // Optimistically empty the tray so the next message starts clean, moving
        // the rows (and their still-live previews) into the snapshot untouched.
        items.value = [];

        for (const { row } of captured) {
            sources.delete(row.localId);
        }

        let settled = false;

        function dispose(): void {
            if (settled) {
                return;
            }

            settled = true;
            outstanding.delete(dispose);

            for (const { row } of captured) {
                if (row.previewUrl) {
                    revokeObjectUrl(row.previewUrl);
                }
            }
        }

        function restore(): void {
            if (settled) {
                return;
            }

            settled = true;
            outstanding.delete(dispose);

            for (const { row, source } of captured) {
                if (source) {
                    sources.set(row.localId, source);
                }
            }

            // Ahead of anything the user staged while the send was in flight.
            items.value = [...captured.map(({ row }) => row), ...items.value];
        }

        outstanding.add(dispose);

        return { restore, dispose };
    }

    function clear(): void {
        // Iterate the live rows, not `sources`: a remote (Giphy) row created by
        // addRemote has no `sources` entry, so keying off rows removes it too.
        for (const item of [...items.value]) {
            remove(item.localId);
        }
    }

    onScopeDispose(() => {
        clear();

        // Release the previews still held by any send whose outcome never landed.
        for (const dispose of [...outstanding]) {
            dispose();
        }
    });

    return {
        items,
        count,
        isUploading,
        hasFailed,
        attachmentIds,
        addFiles,
        addRemote,
        remove,
        retry,
        detach,
        clear,
    };
}
