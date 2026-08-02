<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import AttachmentTray from '@/components/composer/AttachmentTray.vue';
import ComposerInputRow from '@/components/composer/ComposerInputRow.vue';
import EditingBanner from '@/components/composer/EditingBanner.vue';
import MentionMenu from '@/components/composer/MentionMenu.vue';
import RecordingStrip from '@/components/composer/RecordingStrip.vue';
import ReplyPreview from '@/components/composer/ReplyPreview.vue';
import SlashCommandMenu from '@/components/composer/SlashCommandMenu.vue';
import GifPickerPanel from '@/components/GifPickerPanel.vue';
import PollComposerPanel from '@/components/PollComposerPanel.vue';
import ScheduleMessageDialog from '@/components/ScheduleMessageDialog.vue';
import { useAutocompleteAria } from '@/composables/useAutocompleteMenu';
import { useComposerAttachments } from '@/composables/useComposerAttachments';
import { useComposerAttachSheet } from '@/composables/useComposerAttachSheet';
import { useComposerEditMode } from '@/composables/useComposerEditMode';
import { useComposerField } from '@/composables/useComposerField';
import { useComposerFormat } from '@/composables/useComposerFormat';
import { useComposerGifPicker } from '@/composables/useComposerGifPicker';
import { useComposerKeyboard } from '@/composables/useComposerKeyboard';
import { useComposerMentions } from '@/composables/useComposerMentions';
import { useComposerPollBuilder } from '@/composables/useComposerPollBuilder';
import { useComposerSend } from '@/composables/useComposerSend';
import { useComposerSlashCommands } from '@/composables/useComposerSlashCommands';
import { useEllipsizedText } from '@/composables/useEllipsizedText';
import { useKeyboardInset } from '@/composables/useKeyboardInset';
import type {
    CommandCallbacks,
    SendCallbacks,
} from '@/composables/useMessageActions';
import { useTranslations } from '@/composables/useTranslations';
import { isInteractiveComposerTarget } from '@/lib/composerFocus';
import type { Mention, Message } from '@/types';

const props = defineProps<{
    channelName: string;
    members: Mention[];
    // Whether this channel has any bot members. Bots are excluded from `members`
    // (they can't be mentioned), so the mention menu explains their absence with
    // a quiet footnote only when at least one is present.
    hasBots?: boolean;
    replyTarget?: Message | null;
    placeholder?: string;
    allowSendToChannel?: boolean;
    autofocus?: boolean;
    /**
     * Text to seed the composer with on mount, e.g. a persisted draft. Restored
     * verbatim (mention tokens included) so it round-trips faithfully.
     */
    initialBody?: string;
    /**
     * Whether to offer the "schedule for later" affordance (main channel
     * composer only). The viewer's zone drives the picker's presets.
     */
    allowSchedule?: boolean;
    timezone?: string | null;
    /**
     * The messages of the surface this composer posts to (main timeline or the
     * open thread), oldest first. ArrowUp on an empty composer loads the
     * viewer's most recent editable one from here into an inline edit mode.
     */
    messages?: Message[];
    /** The viewer's id, resolving which of `messages` they may edit. */
    currentUserId?: string;
    /**
     * Client uuids of the viewer's in-flight optimistic sends; those rows have
     * no stable id yet and are skipped when resolving the edit target.
     */
    pendingUuids?: string[];
    /**
     * The team and channel slugs the composer posts to. Both are required to
     * enable attachments: files pre-upload to this channel's endpoint, so
     * without them the "Add attachment" button stays disabled (e.g. the thread
     * composer, which does not carry a channel slug).
     */
    teamSlug?: string;
    channelSlug?: string;
    /**
     * The per-file and per-message attachment caps, pre-checked client-side for
     * instant feedback (the server re-enforces both).
     */
    maxAttachmentSizeMb?: number;
    maxAttachmentsPerMessage?: number;
    /**
     * The server's slash-command autocomplete manifest. Passed only where slash
     * commands apply (the main channel composer); absent/empty elsewhere (e.g.
     * the thread composer), which disables all slash handling.
     */
    slashCommands?: App.Data.SlashCommandData[];
    /**
     * Whether the Giphy `/gif` picker is available (an API key is configured).
     * When false, `/gif` is neither in the manifest nor intercepted here.
     */
    gifPickerEnabled?: boolean;
    /**
     * Whether the `/poll` builder is available (POLLS_ENABLED). When false,
     * `/poll` is neither in the manifest nor intercepted here.
     */
    pollsEnabled?: boolean;
}>();

const emit = defineEmits<{
    send: [
        body: string,
        mentions: Mention[],
        sendToChannel: boolean,
        attachmentIds: string[],
        /**
         * Outcome hooks: the tray is emptied optimistically on send, so a failed
         * online send restores the staged attachments (and body) through these.
         */
        callbacks: SendCallbacks,
    ];
    /**
     * A slash command was typed and sent. The raw body goes to the server, which
     * parses it authoritatively; the callbacks clear the composer on success and
     * keep the text on error (the send is non-optimistic).
     */
    command: [body: string, callbacks: CommandCallbacks];
    typing: [];
    cancelReply: [];
    /**
     * The composer body changed (typed, restored-then-edited, or cleared on
     * send). The parent decides whether to persist it as a draft.
     */
    draftChange: [body: string];
    /** The composer text should be delivered later, at the chosen UTC instant. */
    schedule: [body: string, mentions: Mention[], sendAt: string];
    /**
     * Save an inline composer edit through the same PATCH path the message
     * list's inline editor uses.
     */
    edit: [message: Message, body: string];
    /**
     * The composer entered (message id) or left (null) edit mode, so the
     * parent can highlight the target row in the timeline.
     */
    editingChange: [messageId: string | null];
}>();

const { t } = useTranslations();

const field = useComposerField(props.initialBody ?? '');
const { body, textarea, focus, resize } = field;

const attachments = useComposerAttachments({
    teamSlug: () => props.teamSlug,
    channelSlug: () => props.channelSlug,
    maxSizeMb: () => props.maxAttachmentSizeMb,
    maxPerMessage: () => props.maxAttachmentsPerMessage,
});
const {
    attachmentsEnabled,
    canRecord,
    onPaste,
    recorder,
    showTray,
    trayItems,
    uploads,
} = attachments;

/** Take the field element the input row mounts, which the composer drives. */
function registerField(element: HTMLTextAreaElement | null): void {
    textarea.value = element;
}

function onFieldInput(): void {
    resize();
    refreshSuggestions();
    refreshSlashSuggestions();
    emit('typing');
}

function onFieldClick(): void {
    refreshSuggestions();
    refreshSlashSuggestions();
}

const composerPlaceholder = computed(
    () =>
        props.placeholder ??
        t('Message #:channel', { channel: props.channelName }),
);

/**
 * The placeholder as actually rendered: ellipsized to one line of the
 * textarea so a long DM recipient name never wraps and grows the empty
 * composer (#802). The aria-label keeps the full `composerPlaceholder`.
 */
const visiblePlaceholder = useEllipsizedText(textarea, composerPlaceholder);

// Focus on mount when asked (e.g. the thread composer when a thread opens) so
// the user can type straight away without clicking into the field. A restored
// draft also needs a resize so a multi-line draft opens fully expanded.
onMounted(() => {
    nextTick(() => {
        resize();

        if (props.autofocus) {
            textarea.value?.focus();
        }
    });
});

// Focus the composer whenever a reply is started so the user can type straight
// away without reaching for the mouse.
watch(
    () => props.replyTarget,
    (target) => {
        if (target) {
            focus();
        }
    },
);

const mentions = useComposerMentions({
    field,
    members: () => props.members,
});
const { insertMention, refreshSuggestions } = mentions;

/**
 * When true, the next body change is a programmatic clear-on-send (or the wipe
 * that leaves edit mode), not a user edit, so it must not emit a draft change:
 * sending already clears the draft server-side, and re-emitting would fire a
 * redundant save.
 */
let clearingAfterSend = false;

function suppressNextDraftChange(suppress: boolean): void {
    clearingAfterSend = suppress;
}

const editing = useComposerEditMode({
    field,
    messages: () => props.messages ?? [],
    currentUserId: () => props.currentUserId ?? '',
    pendingUuids: () => props.pendingUuids,
    closeMenu: mentions.menu.close,
    suppressNextDraftChange,
    onEditingChange: (messageId) => emit('editingChange', messageId),
    onSave: (message, edited) => emit('edit', message, edited),
});
const { editingMessage, exitEditMode, saveEdit } = editing;

// Surface every body change so the parent can persist (or clear) the draft.
// Seeding `body` above happens before this watch is registered, so restoring a
// draft doesn't echo back as a change.
watch(body, (value) => {
    if (clearingAfterSend) {
        clearingAfterSend = false;

        return;
    }

    // A body change while editing an existing message is scoped to that
    // message, not a new-message draft, so it must never persist as a draft.
    if (editingMessage.value) {
        return;
    }

    emit('draftChange', value);
});

// The adapter is declared before the two pickers so they can dismiss its menu,
// and reads them back through a getter it only calls once a command is chosen.
const slash = useComposerSlashCommands({
    field,
    commands: () => props.slashCommands,
    pickers: () =>
        [gif.command.value, poll.command.value].filter((command) => !!command),
});
const { refreshSuggestions: refreshSlashSuggestions } = slash;

const gif = useComposerGifPicker({
    field,
    gifPickerEnabled: () => Boolean(props.gifPickerEnabled),
    attachmentsEnabled: () => attachmentsEnabled.value,
    isEditing: () => editingMessage.value !== null,
    closeSlashMenu: slash.menu.close,
    stageRemote: (attachment) => uploads.addRemote(attachment),
});

const poll = useComposerPollBuilder({
    field,
    pollsEnabled: () => Boolean(props.pollsEnabled),
    teamSlug: () => props.teamSlug,
    channelSlug: () => props.channelSlug,
    isEditing: () => editingMessage.value !== null,
    closeSlashMenu: slash.menu.close,
});

// The two autocompletes are mutually exclusive — a `/…` body never matches an
// `@query` — so the field advertises whichever of them is up.
const { openListboxId, activeOptionId } = useAutocompleteAria([
    mentions.menu,
    slash.menu,
]);

/**
 * How much of the screen the on-screen keyboard covers, so the pill can sit
 * above it with its Send button reachable instead of behind it.
 *
 * The root pads itself by this on top of the device's home-indicator inset, so
 * the pill stays clear of both: the safe-area inset is static, while the
 * keyboard inset has to be measured live off visualViewport (the layout viewport
 * `dvh` sizes against does not shrink when the keyboard opens).
 *
 * That note lives here rather than above the template's root element on purpose.
 * Vue keeps a root-level comment in a dev build and strips it in production, so
 * a leading comment would make this component a fragment under the dev server —
 * and `$el`, which the page measures for the bottom-right rail's inset, would be
 * the fragment's anchor comment instead of the root div (#1051).
 */
const keyboardInsetPx = useKeyboardInset();

const attachSheet = useComposerAttachSheet({
    field,
    keyboardInsetPx,
    refreshSlashSuggestions,
});
const {
    insetPx: composerInsetPx,
    open: attachSheetOpen,
    startSlashCommand,
} = attachSheet;

const format = useComposerFormat({ field, selection: attachSheet.selection });
const { applyFormat, formatActions } = format;

const send = useComposerSend({
    field,
    attachments,
    mentions,
    slash,
    suppressNextDraftChange,
    onSend: (text, sentMentions, toChannel, attachmentIds, callbacks) =>
        emit('send', text, sentMentions, toChannel, attachmentIds, callbacks),
    onCommand: (rawBody, callbacks) => emit('command', rawBody, callbacks),
    onSchedule: (text, sentMentions, sendAt) =>
        emit('schedule', text, sentMentions, sendAt),
    onDraftChange: (value) => emit('draftChange', value),
});
const {
    canSchedule,
    canSubmit,
    commandPending,
    onScheduleConfirm,
    openSchedule,
    scheduling,
    sendToChannel,
    submit,
} = send;

const { onKeydown } = useComposerKeyboard({
    field,
    mentions,
    slash,
    format,
    editing,
    replyTarget: () => props.replyTarget,
    onCancelReply: () => emit('cancelReply'),
    onSubmit: submit,
});

/**
 * Whether the compose tools are disclosed. Only consulted from `md` up in a
 * narrow container (the desktop thread panel), where they fold away behind a
 * toggle so the field keeps the pill's width; below `md` they live in the
 * attach sheet instead, and once the container widens they are always in line.
 */
const toolsOpen = ref(false);

/**
 * Whether the composer carries anything to send. The mobile disc's mode keys
 * off this rather than `canSubmit`, which also goes false mid-upload and would
 * turn the disc back into a mic with a file still climbing the wire — starting
 * a recording on the next tap.
 */
const hasContent = computed(
    () => body.value.trim() !== '' || trayItems.value.length > 0,
);

function focusFromCard(event: MouseEvent): void {
    const el = textarea.value;

    if (
        !el ||
        isInteractiveComposerTarget(
            event.target as Element | null,
            event.currentTarget as Element,
        )
    ) {
        return;
    }

    event.preventDefault();
    el.focus();

    const end = el.value.length;
    el.setSelectionRange(end, end);
}

defineExpose({ insertMention, focus, addFiles: attachments.addFiles });
</script>

<template>
    <div
        class="@container mx-3 mb-2 shrink-0 md:mx-5 md:mb-4"
        :style="{
            paddingBottom: `calc(env(safe-area-inset-bottom) + ${composerInsetPx}px)`,
        }"
    >
        <div class="relative">
            <MentionMenu
                v-if="mentions.menu.showMenu.value"
                :menu="mentions.menu"
                :has-bots="props.hasBots"
            />

            <SlashCommandMenu
                v-if="slash.menu.showMenu.value"
                :menu="slash.menu"
            />

            <!-- The Giphy picker, opened by `/gif`. Sits in the same anchored
                 position as the autocomplete menus; picking a GIF stages it in
                 the attachment tray below. -->
            <GifPickerPanel
                v-if="gif.open.value && gif.available.value"
                :team-slug="props.teamSlug ?? ''"
                :channel-slug="props.channelSlug ?? ''"
                :initial-query="gif.query.value"
                @select="gif.onSelected"
                @close="gif.close"
            />

            <PollComposerPanel
                v-if="poll.open.value && poll.available.value"
                :team-slug="props.teamSlug ?? ''"
                :channel-slug="props.channelSlug ?? ''"
                @close="poll.close"
            />

            <ReplyPreview
                v-if="props.replyTarget"
                :target="props.replyTarget"
                @cancel="emit('cancelReply')"
            />

            <EditingBanner v-if="editingMessage" @cancel="exitEditMode" />

            <!-- Floating pill: input on the left, ghost tool icons and the ink
                 send circle tucked to the right. Grows upward as the textarea
                 wraps, with the tools pinned to the bottom edge. An attachment
                 tray, when present, sits inside the pill above the input row so
                 the pill stretches to fit rather than overlaying the text. -->
            <div
                class="flex flex-col overflow-hidden rounded-[26px] border bg-card shadow-[0_3px_12px_rgba(29,26,21,0.08)] dark:shadow-[0_3px_12px_rgba(0,0,0,0.3)]"
                :class="
                    recorder.isRecording.value
                        ? 'border-destructive/50 ring-[3px] ring-destructive/10'
                        : editingMessage
                          ? 'border-brass ring-[3px] ring-brass/20'
                          : 'border-input focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/20'
                "
                @mousedown="focusFromCard"
            >
                <AttachmentTray
                    v-if="showTray"
                    :items="trayItems"
                    @remove="uploads.remove"
                    @retry="uploads.retry"
                />

                <RecordingStrip
                    v-if="recorder.isRecording.value"
                    :elapsed-seconds="recorder.elapsedSeconds.value"
                    :is-nearing-limit="recorder.isNearingLimit.value"
                    :level="recorder.level.value"
                    @cancel="recorder.cancel"
                    @stop="recorder.stop"
                />

                <ComposerInputRow
                    v-else
                    v-model="body"
                    v-model:attach-sheet-open="attachSheetOpen"
                    :placeholder="visiblePlaceholder"
                    :field-label="composerPlaceholder"
                    :open-listbox-id="openListboxId"
                    :active-option-id="activeOptionId"
                    :editing="editingMessage !== null"
                    :format-actions="formatActions"
                    :attachments-enabled="attachmentsEnabled"
                    :can-record="canRecord"
                    :tools-open="toolsOpen"
                    :can-submit="canSubmit && !commandPending"
                    :can-schedule="canSchedule"
                    :allow-schedule="Boolean(props.allowSchedule)"
                    :timezone="props.timezone ?? null"
                    :has-content="hasContent"
                    :gif-available="gif.available.value"
                    :poll-available="poll.available.value"
                    :has-slash-commands="(props.slashCommands ?? []).length > 0"
                    :register="registerField"
                    @input="onFieldInput"
                    @paste="onPaste"
                    @click="onFieldClick"
                    @keydown="onKeydown"
                    @files="uploads.addFiles"
                    @format="applyFormat"
                    @record="recorder.start"
                    @toggle-tools="toolsOpen = !toolsOpen"
                    @gif="gif.openPicker('')"
                    @poll="poll.openBuilder"
                    @command="startSlashCommand"
                    @send="submit"
                    @schedule-at="onScheduleConfirm"
                    @custom-time="openSchedule"
                    @save-edit="saveEdit"
                    @cancel-edit="exitEditMode"
                />
            </div>

            <!-- The 14px box is far too small to aim at on a phone, but growing
                 it would make a checkbox the loudest thing under the composer.
                 The label already wraps the text, so below `md` the whole row
                 becomes the target instead and the box is left alone (#920). -->
            <label
                v-if="props.allowSendToChannel && !editingMessage"
                data-test="send-to-channel-row"
                class="mt-2 flex w-full cursor-pointer items-center gap-1.5 px-1.5 text-[12px] text-muted-foreground select-none max-md:min-h-11 md:w-fit"
            >
                <input
                    v-model="sendToChannel"
                    type="checkbox"
                    data-test="send-to-channel"
                    class="size-3.5 shrink-0 rounded border-input accent-primary"
                />
                {{
                    $t('Also send to #:channel', { channel: props.channelName })
                }}
            </label>
        </div>

        <ScheduleMessageDialog
            v-if="props.allowSchedule"
            v-model:open="scheduling"
            :timezone="props.timezone ?? null"
            @confirm="onScheduleConfirm"
        />
    </div>
</template>
