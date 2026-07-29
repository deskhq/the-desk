import { computed, nextTick, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { ComposerAttachments } from '@/composables/useComposerAttachments';
import type { ComposerField } from '@/composables/useComposerField';
import type { ComposerMentions } from '@/composables/useComposerMentions';
import type { ComposerSlashCommands } from '@/composables/useComposerSlashCommands';
import type {
    CommandCallbacks,
    SendCallbacks,
} from '@/composables/useMessageActions';
import type { Mention } from '@/types';

export type ComposerSend = {
    /** In a thread composer, whether the reply is also surfaced in the main timeline. */
    sendToChannel: Ref<boolean>;
    /** Whether there is something to send and nothing blocking it. */
    canSubmit: ComputedRef<boolean>;
    /** True while a command send awaits the server, blocking re-submits. */
    commandPending: Ref<boolean>;
    /** Whether the schedule picker is open. */
    scheduling: Ref<boolean>;
    /** Whether there is text to schedule (scheduling never carries attachments). */
    canSchedule: ComputedRef<boolean>;
    submit: () => void;
    openSchedule: () => void;
    onScheduleConfirm: (sendAt: string) => void;
};

/**
 * Where the composed text leaves the composer: the choice between the
 * optimistic message path, the non-optimistic slash-command path, and the two
 * commands that open a picker instead — plus scheduling it for later, and the
 * clear-down each of those ends in.
 */
export function useComposerSend(options: {
    field: ComposerField;
    attachments: ComposerAttachments;
    mentions: ComposerMentions;
    slash: ComposerSlashCommands;
    /**
     * Flag the next body change as programmatic, so a clear-on-send is not
     * re-persisted as a draft (the send already cleared it server-side).
     */
    suppressNextDraftChange: (suppress: boolean) => void;
    onSend: (
        body: string,
        mentions: Mention[],
        sendToChannel: boolean,
        attachmentIds: string[],
        callbacks: SendCallbacks,
    ) => void;
    onCommand: (body: string, callbacks: CommandCallbacks) => void;
    onSchedule: (body: string, mentions: Mention[], sendAt: string) => void;
    /** Re-persist the retained text after a command send failed. */
    onDraftChange: (body: string) => void;
}): ComposerSend {
    const { body, resize } = options.field;
    const { uploads } = options.attachments;

    const sendToChannel = ref(false);
    const commandPending = ref(false);

    /**
     * A send is allowed once nothing is mid-upload or failed and there is something
     * to send (body text or at least one finished attachment). Body is optional
     * when the tray carries a ready attachment.
     */
    const canSubmit = computed(() => {
        if (uploads.isUploading.value || uploads.hasFailed.value) {
            return false;
        }

        return (
            body.value.trim() !== '' || uploads.attachmentIds.value.length > 0
        );
    });

    /**
     * Clear the composer after the text has been handed off (an immediate send or a
     * scheduled one), flagging the wipe so it doesn't re-persist as a draft.
     */
    function clearAfterHandoff(): void {
        // Suppress the draft echo from the wipe only when there is text to
        // clear; an attachment-only send leaves the body empty throughout, and
        // clearing an already-empty field fires no watcher — which would leave
        // the guard armed to swallow the next genuine keystroke instead.
        options.suppressNextDraftChange(body.value !== '');
        body.value = '';
        sendToChannel.value = false;
        options.mentions.closeMenu();
        options.slash.closeSlashMenu();
        nextTick(resize);
    }

    /**
     * Send a slash command. Non-optimistic: the composer keeps the typed text in a
     * pending state, clears it once the server confirms the command ran, and keeps
     * it if the command failed so the user can correct and resend.
     *
     * The field stays editable while the send is in flight, so both outcomes guard
     * against clobbering a fresh edit: success clears only if the body is still the
     * one that was sent, and failure re-emits the draft (the command send cancels
     * the debounced save, so the retained text must reschedule its persistence).
     */
    function submitCommand(rawBody: string): void {
        const submittedBody = body.value;
        commandPending.value = true;
        options.slash.closeSlashMenu();

        options.onCommand(rawBody, {
            onSuccess: () => {
                commandPending.value = false;

                if (body.value === submittedBody) {
                    clearAfterHandoff();
                }
            },
            onError: () => {
                commandPending.value = false;
                options.onDraftChange(body.value);
            },
        });
    }

    function submit(): void {
        if (!canSubmit.value || commandPending.value) {
            return;
        }

        const trimmed = body.value.trim();

        // A slash command forks off the optimistic message path onto a dedicated,
        // non-optimistic send. Only when the tray is empty — a command carries no
        // attachments, so a command-looking body with staged files posts as text.
        if (
            uploads.attachmentIds.value.length === 0 &&
            options.slash.looksLikeCommand(trimmed)
        ) {
            // `/gif [query]` opens the picker rather than posting text; the chosen
            // GIF is then sent as an attachment through the ordinary path.
            const gifQuery = options.slash.gifCommandQuery(trimmed);

            if (gifQuery !== null) {
                options.slash.openGifPicker(gifQuery);

                return;
            }

            // `/poll` opens the builder rather than posting text; the composed poll
            // is then posted as a first-class poll message through its own endpoint.
            if (options.slash.isPollCommand(trimmed)) {
                options.slash.openPollComposer();

                return;
            }

            submitCommand(trimmed);

            return;
        }

        // Snapshot the composer state before the optimistic wipe: the send is
        // fire-and-forget, so if an online send fails, the staged attachments (and
        // the typed body) are handed back through the outcome hooks below, letting
        // the user retry without re-picking every file. A successful (or queued)
        // send disposes the snapshot instead. `detach` empties the tray but keeps the
        // rows' previews alive until the outcome lands.
        const stagedBody = body.value;
        // The clear-down resets the thread composer's "also send to channel"
        // tick too, so it belongs in the snapshot: without it a rejected reply
        // comes back thread-only and the retry quietly drops the choice.
        const stagedSendToChannel = sendToChannel.value;
        const attachmentIds = uploads.attachmentIds.value;
        const snapshot = uploads.detach();

        options.onSend(
            trimmed,
            options.mentions.collectMentions(trimmed),
            stagedSendToChannel,
            attachmentIds,
            {
                onAccepted: () => snapshot.dispose(),
                onRejected: () => {
                    snapshot.restore();
                    body.value = stagedBody;
                    sendToChannel.value = stagedSendToChannel;
                },
            },
        );
        clearAfterHandoff();
    }

    /**
     * Whether the schedule picker is open. Opening requires some text — an empty
     * composer has nothing to schedule.
     */
    const scheduling = ref(false);

    /**
     * Scheduling never carries attachments, so the "Send later" affordances gate on
     * body text alone, matching the old schedule button's guard.
     */
    const canSchedule = computed(() => body.value.trim() !== '');

    function openSchedule(): void {
        if (!canSchedule.value) {
            return;
        }

        scheduling.value = true;
    }

    function onScheduleConfirm(sendAt: string): void {
        const trimmed = body.value.trim();

        if (trimmed === '') {
            return;
        }

        options.onSchedule(
            trimmed,
            options.mentions.collectMentions(trimmed),
            sendAt,
        );
        clearAfterHandoff();
    }

    return {
        sendToChannel,
        canSubmit,
        commandPending,
        scheduling,
        canSchedule,
        submit,
        openSchedule,
        onScheduleConfirm,
    };
}
