import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { store as storeAttachment } from '@/actions/App/Http/Controllers/Channels/AttachmentController';
import type {
    AttachmentUploads,
    PendingAttachment,
} from '@/composables/useAttachmentUploads';
import { useChannelUploads } from '@/composables/useChannelUploads';
import { useVoiceRecorder } from '@/composables/useVoiceRecorder';
import type { VoiceRecorder } from '@/composables/useVoiceRecorder';
import { isVoiceRecordingSupported } from '@/lib/audio';

export type ComposerAttachments = {
    /** Whether the composer knows its channel, which is what attachments need. */
    attachmentsEnabled: ComputedRef<boolean>;
    /** The pre-send tray itself: staging, progress, removal, and the claimable ids. */
    uploads: AttachmentUploads;
    trayItems: ComputedRef<PendingAttachment[]>;
    showTray: ComputedRef<boolean>;
    /** Whether the mic slot applies at all in this browser and this channel. */
    canRecord: ComputedRef<boolean>;
    recorder: VoiceRecorder;
    /** Stage pasted image data or files instead of dropping raw bytes into the text. */
    onPaste: (event: ClipboardEvent) => void;
    /** Stage externally-provided files (the channel pane's drag-and-drop overlay). */
    addFiles: (files: FileList | File[]) => void;
};

/**
 * Everything the composer stages before a send: the pre-send attachment tray,
 * the paths files reach it by (pick, paste, drop), and voice recording — which
 * is nothing but an audio attachment, so it rides the same tray and endpoint.
 */
export function useComposerAttachments(options: {
    teamSlug: () => string | undefined;
    channelSlug: () => string | undefined;
    /** The per-file size cap in megabytes, defaulting to the server's own. */
    maxSizeMb: () => number | undefined;
    /** The per-message file-count cap, defaulting to the server's own. */
    maxPerMessage: () => number | undefined;
}): ComposerAttachments {
    /**
     * Attachments are enabled only when the composer knows its channel: files
     * pre-upload to that channel's endpoint. The thread composer omits the slug, so
     * its "Add attachment" button stays disabled (thread attachments are a separate
     * epic child).
     */
    const attachmentsEnabled = computed(
        () => Boolean(options.teamSlug()) && Boolean(options.channelSlug()),
    );

    /**
     * The pre-send attachment tray: files upload immediately on pick/paste/drop,
     * each row tracking its own progress; the send later claims the finished ids.
     *
     * It belongs to the channel, not to this composer, so leaving the channel
     * mid-upload no longer aborts the transfer. Resolved once: a composer is
     * mounted per channel and remounts when that changes.
     */
    const channelKey = attachmentsEnabled.value
        ? `${options.teamSlug()}/${options.channelSlug()}`
        : null;

    const uploads = useChannelUploads({
        channelKey,
        endpoint: () =>
            storeAttachment({
                team: options.teamSlug() ?? '',
                channel: options.channelSlug() ?? '',
            }).url,
        maxSizeMb: () => options.maxSizeMb() ?? 25,
        maxPerMessage: () => options.maxPerMessage() ?? 10,
    });

    const trayItems = computed(() => uploads.items.value);
    const showTray = computed(
        () => attachmentsEnabled.value && trayItems.value.length > 0,
    );

    /**
     * A voice clip is nothing but an audio attachment, so recording rides the tray
     * above: the finished clip is staged exactly like a dropped file and uploads
     * through the same endpoint. Capability is read once — `MediaRecorder` and a
     * secure-context `getUserMedia` don't appear mid-session — and where either is
     * missing the mic slot is absent rather than present-and-broken.
     */
    const voiceRecordingSupported = isVoiceRecordingSupported();
    const canRecord = computed(
        () => attachmentsEnabled.value && voiceRecordingSupported,
    );

    const recorder = useVoiceRecorder({
        onRecorded: (clip) => uploads.addFiles([clip]),
    });

    function onPaste(event: ClipboardEvent): void {
        if (!attachmentsEnabled.value) {
            return;
        }

        const files = Array.from(event.clipboardData?.files ?? []);

        if (files.length > 0) {
            event.preventDefault();
            uploads.addFiles(files);
        }
    }

    function addFiles(files: FileList | File[]): void {
        if (attachmentsEnabled.value) {
            uploads.addFiles(files);
        }
    }

    return {
        attachmentsEnabled,
        uploads,
        trayItems,
        showTray,
        canRecord,
        recorder,
        onPaste,
        addFiles,
    };
}
