<script setup lang="ts">
import {
    ArrowUp,
    Bold,
    CalendarClock,
    CircleAlert,
    Code,
    FileText,
    Italic,
    Pencil,
    Plus,
    Strikethrough,
    X,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { store as storeAttachment } from '@/actions/App/Http/Controllers/Channels/AttachmentController';
import MessageQuote from '@/components/MessageQuote.vue';
import ScheduleMessageDialog from '@/components/ScheduleMessageDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAttachmentUploads } from '@/composables/useAttachmentUploads';
import { useInitials } from '@/composables/useInitials';
import { useTranslations } from '@/composables/useTranslations';
import { formatFileSize } from '@/lib/attachments';
import {
    isComposerEditTrigger,
    resolveComposerEditTarget,
} from '@/lib/composerEdit';
import { isInteractiveComposerTarget } from '@/lib/composerFocus';
import { toggleInlineMark } from '@/lib/composerFormat';
import type { Mention, Message } from '@/types';

const props = defineProps<{
    channelName: string;
    members: Mention[];
    replyTarget?: Message | null;
    placeholder?: string;
    allowSendToChannel?: boolean;
    autofocus?: boolean;
    // Text to seed the composer with on mount, e.g. a persisted draft. Restored
    // verbatim (mention tokens included) so it round-trips faithfully.
    initialBody?: string;
    // Whether to offer the "schedule for later" affordance (main channel
    // composer only). The viewer's zone drives the picker's presets.
    allowSchedule?: boolean;
    timezone?: string | null;
    // The messages of the surface this composer posts to (main timeline or the
    // open thread), oldest first. ArrowUp on an empty composer loads the
    // viewer's most recent editable one from here into an inline edit mode.
    messages?: Message[];
    // The viewer's id, resolving which of `messages` they may edit.
    currentUserId?: string;
    // Client uuids of the viewer's in-flight optimistic sends; those rows have
    // no stable id yet and are skipped when resolving the edit target.
    pendingUuids?: string[];
    // The team and channel slugs the composer posts to. Both are required to
    // enable attachments: files pre-upload to this channel's endpoint, so
    // without them the "Add attachment" button stays disabled (e.g. the thread
    // composer, which does not carry a channel slug).
    teamSlug?: string;
    channelSlug?: string;
    // The per-file and per-message attachment caps, pre-checked client-side for
    // instant feedback (the server re-enforces both).
    maxAttachmentSizeMb?: number;
    maxAttachmentsPerMessage?: number;
}>();

const emit = defineEmits<{
    send: [
        body: string,
        mentions: Mention[],
        sendToChannel: boolean,
        attachmentIds: string[],
    ];
    typing: [];
    cancelReply: [];
    // The composer body changed (typed, restored-then-edited, or cleared on
    // send). The parent decides whether to persist it as a draft.
    draftChange: [body: string];
    // The composer text should be delivered later, at the chosen UTC instant.
    schedule: [body: string, mentions: Mention[], sendAt: string];
    // Save an inline composer edit through the same PATCH path the message
    // list's inline editor uses.
    edit: [message: Message, body: string];
    // The composer entered (message id) or left (null) edit mode, so the
    // parent can highlight the target row in the timeline.
    editingChange: [messageId: string | null];
}>();

const { getInitials } = useInitials();
const { t } = useTranslations();

const body = ref(props.initialBody ?? '');
const textarea = ref<HTMLTextAreaElement | null>(null);

// The message the composer is editing in place, or null in normal compose mode.
// Set by the ArrowUp "edit last message" shortcut; while non-null the body is
// scoped to that message, not a new-message draft.
const editingMessage = ref<Message | null>(null);

// When true, the next body change is a programmatic clear-on-send (or the wipe
// that leaves edit mode), not a user edit, so it must not emit a draft change:
// sending already clears the draft server-side, and re-emitting would fire a
// redundant save.
let clearingAfterSend = false;

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

// In a thread composer, whether the reply is also surfaced in the main timeline.
const sendToChannel = ref(false);

// Attachments are enabled only when the composer knows its channel: files
// pre-upload to that channel's endpoint. The thread composer omits the slug, so
// its "Add attachment" button stays disabled (thread attachments are a separate
// epic child).
const attachmentsEnabled = computed(
    () => Boolean(props.teamSlug) && Boolean(props.channelSlug),
);

// The pre-send attachment tray: files upload immediately on pick/paste/drop,
// each row tracking its own progress; the send later claims the finished ids.
const uploads = useAttachmentUploads({
    endpoint: () =>
        storeAttachment({
            team: props.teamSlug ?? '',
            channel: props.channelSlug ?? '',
        }).url,
    maxSizeMb: () => props.maxAttachmentSizeMb ?? 25,
    maxPerMessage: () => props.maxAttachmentsPerMessage ?? 10,
});

const trayItems = computed(() => uploads.items.value);
const showTray = computed(
    () => attachmentsEnabled.value && trayItems.value.length > 0,
);

// A send is allowed once nothing is mid-upload or failed and there is something
// to send (body text or at least one finished attachment). Body is optional
// when the tray carries a ready attachment.
const canSubmit = computed(() => {
    if (uploads.isUploading.value || uploads.hasFailed.value) {
        return false;
    }

    return body.value.trim() !== '' || uploads.attachmentIds.value.length > 0;
});

// The hidden native file input the "Add attachment" button proxies to.
const fileInput = ref<HTMLInputElement | null>(null);

function openFilePicker(): void {
    fileInput.value?.click();
}

function onFilesPicked(event: Event): void {
    const input = event.target as HTMLInputElement;

    if (input.files) {
        uploads.addFiles(input.files);
    }

    // Reset so re-picking the same file fires `change` again.
    input.value = '';
}

// Pasting image data (a screenshot) or files into the composer stages them in
// the tray instead of dropping raw bytes into the text.
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

// Stage externally-provided files (the channel pane's drag-and-drop overlay
// forwards its drop here). Exposed below.
function addFiles(files: FileList | File[]): void {
    if (attachmentsEnabled.value) {
        uploads.addFiles(files);
    }
}

const composerPlaceholder = computed(
    () =>
        props.placeholder ??
        t('Message #:channel', { channel: props.channelName }),
);

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
            nextTick(() => textarea.value?.focus());
        }
    },
);

// Well-formed mention token: `@[Display Name](user-id)`. The parser on the
// server resolves the id; here it lets us collect the mentions being sent and
// recognise a completed token so it never re-triggers the autocomplete.
const MENTION_TOKEN = /@\[[^\]]+\]\(([0-9a-fA-F-]{36})\)/g;

// A fresh `@query` at the caret: an `@` at the start or after whitespace,
// followed by run of non-space characters that isn't already a token.
const MENTION_QUERY = /(?:^|\s)@([^\s@[\]()]*)$/;

const MAX_SUGGESTIONS = 8;

const suggestions = ref<Mention[]>([]);
const activeIndex = ref(0);
const menuOpen = ref(false);

const showMenu = computed(() => menuOpen.value && suggestions.value.length > 0);

/**
 * The active `@query` immediately before the caret, or null when the caret is
 * not in a mention context.
 */
function activeQuery(): { query: string; start: number } | null {
    const el = textarea.value;
    const caret = el ? el.selectionStart : body.value.length;
    const upToCaret = body.value.slice(0, caret);
    const match = upToCaret.match(MENTION_QUERY);

    if (!match) {
        return null;
    }

    return { query: match[1], start: caret - match[1].length - 1 };
}

function refreshSuggestions(): void {
    const active = activeQuery();

    if (!active) {
        menuOpen.value = false;
        suggestions.value = [];

        return;
    }

    const needle = active.query.toLowerCase();

    suggestions.value = props.members
        .filter((member) => member.name.toLowerCase().includes(needle))
        .slice(0, MAX_SUGGESTIONS);
    activeIndex.value = 0;
    menuOpen.value = suggestions.value.length > 0;
}

function moveActive(delta: number): void {
    const count = suggestions.value.length;
    activeIndex.value = (activeIndex.value + delta + count) % count;
}

function selectMember(member: Mention): void {
    const el = textarea.value;
    const caret = el ? el.selectionStart : body.value.length;
    const active = activeQuery();

    if (!active) {
        return;
    }

    const before = body.value.slice(0, active.start);
    const after = body.value.slice(caret);
    const token = `@[${member.name}](${member.id}) `;

    body.value = before + token + after;
    menuOpen.value = false;

    const nextCaret = before.length + token.length;

    nextTick(() => {
        const field = textarea.value;

        if (field) {
            field.focus();
            field.setSelectionRange(nextCaret, nextCaret);
        }

        resize();
    });
}

function selectActive(): void {
    const member = suggestions.value[activeIndex.value];

    if (member) {
        selectMember(member);
    }
}

/**
 * Collect the distinct, resolvable mentions present in the body so the
 * optimistic row can highlight them before the server echo arrives.
 */
function collectMentions(text: string): Mention[] {
    const seen = new Set<string>();
    const mentions: Mention[] = [];

    for (const match of text.matchAll(MENTION_TOKEN)) {
        const id = match[1];
        const member = props.members.find((candidate) => candidate.id === id);

        if (member && !seen.has(id)) {
            seen.add(id);
            mentions.push({ id: member.id, name: member.name });
        }
    }

    return mentions;
}

function resize(): void {
    const el = textarea.value;

    if (!el) {
        return;
    }

    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
}

// The platform's primary modifier, for the format tooltips' shortcut hints.
// Falls back to Ctrl off-Mac (and during SSR, where `navigator` is absent).
const isMac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform);
const modLabel = isMac ? '⌘' : 'Ctrl+';
const shiftLabel = isMac ? '⇧' : 'Shift+';

/**
 * The four inline-format controls, each pairing its Markdown marker with the
 * icon, accessible label, and shortcut hint shown in its tooltip. Driven by the
 * toolbar buttons and the keyboard shortcuts alike.
 */
const formatActions = computed(() => [
    { marker: '**', icon: Bold, label: t('Bold'), shortcut: `${modLabel}B` },
    { marker: '*', icon: Italic, label: t('Italic'), shortcut: `${modLabel}I` },
    {
        marker: '~~',
        icon: Strikethrough,
        label: t('Strikethrough'),
        shortcut: `${modLabel}${shiftLabel}X`,
    },
    {
        marker: '`',
        icon: Code,
        label: t('Inline code'),
        shortcut: `${modLabel}E`,
    },
]);

/**
 * Wrap (or unwrap) the current textarea selection in a Markdown marker, then
 * restore focus and the resulting selection so the field stays ready to type.
 * Shared by the toolbar buttons and the keyboard shortcuts.
 */
function applyFormat(marker: string): void {
    const el = textarea.value;

    if (!el) {
        return;
    }

    const result = toggleInlineMark(
        body.value,
        el.selectionStart,
        el.selectionEnd,
        marker,
    );

    body.value = result.value;

    nextTick(() => {
        const field = textarea.value;

        if (field) {
            field.focus();
            field.setSelectionRange(result.selectionStart, result.selectionEnd);
        }

        resize();
    });
}

/**
 * Handle the format keyboard shortcuts (⌘/Ctrl+B/I/E and ⌘/Ctrl+Shift+X),
 * returning true when one fired so the caller stops further key handling. The
 * chosen keys avoid every existing composer binding.
 */
function tryFormatShortcut(event: KeyboardEvent): boolean {
    if (!(event.metaKey || event.ctrlKey) || event.altKey) {
        return false;
    }

    const key = event.key.toLowerCase();

    const marker =
        key === 'b' && !event.shiftKey
            ? '**'
            : key === 'i' && !event.shiftKey
              ? '*'
              : key === 'e' && !event.shiftKey
                ? '`'
                : key === 'x' && event.shiftKey
                  ? '~~'
                  : null;

    if (marker === null) {
        return false;
    }

    event.preventDefault();
    // Claim the key before it bubbles to window-level shortcuts (⌘/Ctrl+B also
    // toggles the sidebar), so formatting in the composer never fires those too.
    event.stopPropagation();
    applyFormat(marker);

    return true;
}

/**
 * Insert a mention token at the caret (or the end), keeping a space separator
 * from preceding text. Exposed so a profile hover card can drop a mention into
 * the composer from elsewhere in the page.
 */
function insertMention(member: Mention): void {
    const el = textarea.value;
    const caret = el ? el.selectionStart : body.value.length;
    const before = body.value.slice(0, caret);
    const after = body.value.slice(caret);

    const separator = before.length > 0 && !before.endsWith(' ') ? ' ' : '';
    const token = `${separator}@[${member.name}](${member.id}) `;

    body.value = before + token + after;

    const nextCaret = before.length + token.length;

    nextTick(() => {
        const field = textarea.value;

        if (field) {
            field.focus();
            field.setSelectionRange(nextCaret, nextCaret);
        }

        resize();
    });
}

// Focus the input field, e.g. from the brand-new-workspace welcome's "Post your
// first message" action.
function focus(): void {
    nextTick(() => textarea.value?.focus());
}

// The whole card reads as "the message box", so a click anywhere in its chrome —
// the padding, the whitespace around the single line of text — should activate
// the input. Clicks that land on a control (send/attachment/schedule buttons) or
// on the textarea itself carry their own behaviour and are left untouched; only
// clicks on the non-interactive chrome fall through here to focus the field with
// the caret at the end. `mousedown` is preventable before focus shifts, so the
// redirect happens without a flicker of the card losing then regaining focus.
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

defineExpose({ insertMention, focus, addFiles });

// Clear the composer after the text has been handed off (an immediate send or a
// scheduled one), flagging the wipe so it doesn't re-persist as a draft.
function clearAfterHandoff(): void {
    clearingAfterSend = true;
    body.value = '';
    sendToChannel.value = false;
    menuOpen.value = false;
    nextTick(resize);
}

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    const trimmed = body.value.trim();

    emit(
        'send',
        trimmed,
        collectMentions(trimmed),
        sendToChannel.value,
        uploads.attachmentIds.value,
    );
    uploads.clear();
    clearAfterHandoff();
}

/** Whether the caret sits at the very start of the field (nothing selected). */
function caretAtStart(): boolean {
    const el = textarea.value;

    if (!el) {
        return true;
    }

    return el.selectionStart === 0 && el.selectionEnd === 0;
}

/**
 * Load a message's body into the composer and switch to edit mode, placing the
 * caret at the end so the just-recalled text is ready to correct. The body is
 * restored verbatim (mention tokens included) so it round-trips faithfully.
 */
function enterEditMode(message: Message): void {
    editingMessage.value = message;
    body.value = message.body;
    menuOpen.value = false;
    emit('editingChange', message.id);

    nextTick(() => {
        const field = textarea.value;

        if (field) {
            field.focus();

            const end = field.value.length;
            field.setSelectionRange(end, end);
        }

        resize();
    });
}

/**
 * Leave edit mode and reset the composer to its prior (empty) state. The wipe is
 * flagged so it isn't persisted as a channel draft (entry required an empty
 * composer, so there is nothing to restore).
 */
function exitEditMode(): void {
    // Suppress the draft echo from the wipe only when there is text to clear;
    // clearing an already-empty field fires no watcher, which would otherwise
    // leave the guard armed for the next genuine keystroke.
    clearingAfterSend = body.value !== '';
    body.value = '';
    editingMessage.value = null;
    menuOpen.value = false;
    emit('editingChange', null);
    nextTick(resize);
}

/**
 * Resolve the viewer's most recent editable message on this surface and enter
 * edit mode for it. A no-op (falling through to the default ArrowUp behaviour)
 * when there is none.
 */
function tryEditLastMessage(): boolean {
    const target = resolveComposerEditTarget(
        props.messages ?? [],
        props.currentUserId ?? '',
        props.pendingUuids,
    );

    if (!target) {
        return false;
    }

    enterEditMode(target);

    return true;
}

/**
 * Save the in-progress composer edit through the shared edit/PATCH path. An
 * empty or unchanged draft is a no-op, matching the inline editor; either way
 * the composer returns to its normal empty state.
 */
function saveEdit(): void {
    const target = editingMessage.value;

    if (!target) {
        return;
    }

    const trimmed = body.value.trim();

    if (trimmed !== '' && trimmed !== target.body) {
        emit('edit', target, trimmed);
    }

    exitEditMode();
}

// Whether the schedule picker is open. Opening requires some text — an empty
// composer has nothing to schedule.
const scheduling = ref(false);

function openSchedule(): void {
    if (body.value.trim() === '') {
        return;
    }

    scheduling.value = true;
}

function onScheduleConfirm(sendAt: string): void {
    const trimmed = body.value.trim();

    if (trimmed === '') {
        return;
    }

    emit('schedule', trimmed, collectMentions(trimmed), sendAt);
    clearAfterHandoff();
}

function onKeydown(event: KeyboardEvent): void {
    if (showMenu.value) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveActive(1);

            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveActive(-1);

            return;
        }

        if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            selectActive();

            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            menuOpen.value = false;

            return;
        }
    }

    // Format shortcuts wrap the selection. Placed after the mention menu's key
    // handling so its arrow/Enter/Escape keep priority while it is open; the
    // chosen keys (B/I/E, ⇧X) never collide with it or Enter-to-send.
    if (tryFormatShortcut(event)) {
        return;
    }

    // With the mention menu closed, Escape leaves edit mode (restoring the empty
    // composer) or, failing that, dismisses the active reply context.
    if (event.key === 'Escape' && editingMessage.value) {
        event.preventDefault();
        exitEditMode();

        return;
    }

    if (event.key === 'Escape' && props.replyTarget) {
        event.preventDefault();
        emit('cancelReply');

        return;
    }

    // ArrowUp on an empty composer recalls the viewer's last editable message
    // into edit mode ("↑ to edit last message"). The gate keeps it clear of the
    // mention menu, `⌥↑` channel nav, and an in-progress reply.
    if (
        isComposerEditTrigger({
            key: event.key,
            altKey: event.altKey,
            ctrlKey: event.ctrlKey,
            metaKey: event.metaKey,
            shiftKey: event.shiftKey,
            menuOpen: showMenu.value,
            editing: editingMessage.value !== null,
            hasReplyTarget: props.replyTarget != null,
            isEmpty: body.value.trim() === '',
            caretAtStart: caretAtStart(),
        })
    ) {
        if (tryEditLastMessage()) {
            event.preventDefault();
        }

        return;
    }

    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();

        if (editingMessage.value) {
            saveEdit();
        } else {
            submit();
        }
    }
}
</script>

<template>
    <div class="mx-5 mb-4 shrink-0">
        <div class="relative">
            <ul
                v-if="showMenu"
                id="mention-listbox"
                data-test="mention-menu"
                role="listbox"
                :aria-label="$t('Mention a teammate')"
                class="absolute bottom-full left-0 z-10 mb-2 max-h-60 w-64 overflow-y-auto rounded-lg border border-border bg-popover p-1 shadow-md"
            >
                <li
                    v-for="(member, index) in suggestions"
                    :id="`mention-option-${index}`"
                    :key="member.id"
                    data-test="mention-option"
                    role="option"
                    tabindex="-1"
                    :aria-selected="index === activeIndex"
                    class="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-popover-foreground"
                    :class="
                        index === activeIndex
                            ? 'bg-accent text-accent-foreground'
                            : 'hover:bg-accent/60'
                    "
                    @mousedown.prevent="selectMember(member)"
                    @mouseenter="activeIndex = index"
                >
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-md bg-primary/10 text-[10px] font-semibold text-primary select-none"
                        aria-hidden="true"
                    >
                        {{ getInitials(member.name) }}
                    </span>
                    <span class="truncate">{{ member.name }}</span>
                </li>
            </ul>

            <div
                v-if="props.replyTarget"
                data-test="reply-preview"
                class="mb-2 flex items-center gap-2 rounded-2xl border border-input bg-muted/40 px-3.5 py-2"
            >
                <span class="min-w-0 flex-1">
                    <MessageQuote
                        :author-name="props.replyTarget.user.name"
                        :body="props.replyTarget.body"
                        :is-deleted="props.replyTarget.isDeleted"
                    />
                </span>
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="reply-preview-dismiss"
                    :aria-label="$t('Cancel reply')"
                    class="shrink-0 rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                    @click="emit('cancelReply')"
                >
                    <X class="size-3.5" />
                </Button>
            </div>

            <!-- Edit-mode banner: brass-tinted so the composer unmistakably
                 reads as editing an existing message rather than composing a new
                 one, naming how to save or cancel. -->
            <div
                v-if="editingMessage"
                data-test="composer-editing-banner"
                class="mb-2 flex items-center gap-2 rounded-2xl border border-brass-border bg-brass-fill px-3.5 py-2"
            >
                <Pencil class="size-3.5 shrink-0 text-brass" />
                <span
                    class="min-w-0 flex-1 truncate text-[12.5px] font-semibold text-brass-fill-foreground"
                >
                    {{ $t('Editing your message') }}
                </span>
                <span class="shrink-0 text-[11.5px] text-muted-foreground">
                    {{ $t('Enter to save · Esc to cancel') }}
                </span>
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="composer-editing-dismiss"
                    :aria-label="$t('Cancel edit')"
                    class="shrink-0 rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                    @click="exitEditMode"
                >
                    <X class="size-3.5" />
                </Button>
            </div>

            <!-- Floating pill: input on the left, ghost tool icons and the ink
                 send circle tucked to the right. Grows upward as the textarea
                 wraps, with the tools pinned to the bottom edge. An attachment
                 tray, when present, sits inside the pill above the input row so
                 the pill stretches to fit rather than overlaying the text. -->
            <div
                class="flex flex-col overflow-hidden rounded-[26px] border bg-card shadow-[0_3px_12px_rgba(29,26,21,0.08)] dark:shadow-[0_3px_12px_rgba(0,0,0,0.3)]"
                :class="
                    editingMessage
                        ? 'border-brass ring-[3px] ring-brass/20'
                        : 'border-input focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/20'
                "
                @mousedown="focusFromCard"
            >
                <!-- Pre-send attachment tray. Row order is the send order
                     (attachment_ids[]). Removing a row is immediate — the upload
                     is pre-send, so there is nothing to undo. -->
                <div
                    v-if="showTray"
                    data-test="composer-attachment-tray"
                    class="flex flex-wrap gap-2.5 px-4 pt-3.5 pb-1"
                >
                    <template v-for="item in trayItems" :key="item.localId">
                        <!-- Failed upload: a retryable chip. Nothing was
                             persisted; it blocks send until retried or removed. -->
                        <div
                            v-if="item.status === 'failed'"
                            data-test="composer-attachment"
                            data-status="failed"
                            class="relative flex h-19 min-w-50 items-center gap-2.5 rounded-xl border border-destructive/40 bg-destructive/10 px-3"
                        >
                            <span
                                class="flex size-9.5 shrink-0 items-center justify-center rounded-[10px] bg-destructive/15 text-destructive"
                            >
                                <CircleAlert class="size-4.5" />
                            </span>
                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span
                                    class="truncate text-[12.5px] font-semibold text-foreground"
                                >
                                    {{ item.name }}
                                </span>
                                <span class="text-[11px] text-destructive">
                                    {{ $t('Upload failed') }} ·
                                    <Button
                                        variant="unstyled"
                                        size="none"
                                        type="button"
                                        data-test="composer-attachment-retry"
                                        class="underline hover:no-underline"
                                        @click="uploads.retry(item.localId)"
                                    >
                                        {{ $t('Retry') }}
                                    </Button>
                                </span>
                            </span>
                            <Button
                                variant="unstyled"
                                size="none"
                                type="button"
                                data-test="composer-attachment-remove"
                                :aria-label="$t('Remove attachment')"
                                class="shrink-0 rounded-full p-1 text-muted-foreground hover:text-foreground"
                                @click="uploads.remove(item.localId)"
                            >
                                <X class="size-3" />
                            </Button>
                        </div>

                        <!-- Image preview thumbnail (never SVG). -->
                        <div
                            v-else-if="item.isImage"
                            data-test="composer-attachment"
                            :data-status="item.status"
                            class="group relative size-19 overflow-hidden rounded-xl border border-input bg-muted"
                        >
                            <img
                                v-if="item.previewUrl"
                                :src="item.previewUrl"
                                alt=""
                                class="size-full object-cover"
                            />
                            <div
                                v-if="item.status === 'uploading'"
                                class="absolute inset-0 bg-foreground/25"
                            ></div>
                            <div
                                v-if="item.status === 'uploading'"
                                class="absolute inset-x-1.5 bottom-1.5 h-0.75 overflow-hidden rounded-full bg-background/40"
                            >
                                <div
                                    class="h-full rounded-full bg-brass"
                                    :style="{ width: `${item.progress}%` }"
                                ></div>
                            </div>
                            <Button
                                variant="unstyled"
                                size="none"
                                type="button"
                                data-test="composer-attachment-remove"
                                :aria-label="$t('Remove attachment')"
                                class="absolute top-1 right-1 flex size-5.5 items-center justify-center rounded-full bg-foreground/80 text-background opacity-0 transition-opacity group-hover:opacity-100 focus:opacity-100"
                                @click="uploads.remove(item.localId)"
                            >
                                <X class="size-2.75" />
                            </Button>
                        </div>

                        <!-- Non-image file chip (uploading or done). -->
                        <div
                            v-else
                            data-test="composer-attachment"
                            :data-status="item.status"
                            class="group relative flex h-19 min-w-52 items-center gap-2.5 rounded-xl border border-input bg-muted px-3"
                        >
                            <span
                                class="flex size-9.5 shrink-0 items-center justify-center rounded-[10px] bg-background text-muted-foreground"
                            >
                                <FileText class="size-4.5" />
                            </span>
                            <span class="flex min-w-0 flex-1 flex-col gap-1">
                                <span
                                    class="truncate text-[12.5px] font-semibold text-foreground"
                                >
                                    {{ item.name }}
                                </span>
                                <span
                                    class="text-[11px] text-muted-foreground tabular-nums"
                                >
                                    {{ formatFileSize(item.sizeBytes)
                                    }}<template
                                        v-if="item.status === 'uploading'"
                                    >
                                        · {{ item.progress }}%</template
                                    >
                                </span>
                                <div
                                    v-if="item.status === 'uploading'"
                                    class="h-0.75 overflow-hidden rounded-full bg-border"
                                >
                                    <div
                                        class="h-full rounded-full bg-brass"
                                        :style="{ width: `${item.progress}%` }"
                                    ></div>
                                </div>
                            </span>
                            <Button
                                variant="unstyled"
                                size="none"
                                type="button"
                                data-test="composer-attachment-remove"
                                :aria-label="$t('Remove attachment')"
                                class="shrink-0 rounded-full p-1 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 hover:text-foreground focus:opacity-100"
                                @click="uploads.remove(item.localId)"
                            >
                                <X class="size-3" />
                            </Button>
                        </div>
                    </template>
                </div>

                <!-- Input row -->
                <div class="flex items-end gap-2.5 py-2 pr-2 pl-4.5">
                    <textarea
                        ref="textarea"
                        v-model="body"
                        rows="1"
                        :placeholder="composerPlaceholder"
                        :aria-label="composerPlaceholder"
                        data-test="message-composer-input"
                        role="combobox"
                        aria-autocomplete="list"
                        :aria-expanded="showMenu"
                        :aria-controls="
                            showMenu ? 'mention-listbox' : undefined
                        "
                        :aria-activedescendant="
                            showMenu
                                ? `mention-option-${activeIndex}`
                                : undefined
                        "
                        autocomplete="off"
                        autocorrect="off"
                        autocapitalize="sentences"
                        spellcheck="true"
                        data-1p-ignore
                        data-lpignore="true"
                        data-bwignore
                        data-form-type="other"
                        class="max-h-[200px] min-w-0 flex-1 resize-none self-center bg-transparent py-1 text-sm text-foreground outline-none placeholder:text-muted-foreground"
                        @input="
                            (resize(), refreshSuggestions(), emit('typing'))
                        "
                        @paste="onPaste"
                        @click="refreshSuggestions"
                        @keydown="onKeydown"
                    ></textarea>
                    <!-- Edit mode swaps the compose tools for explicit
                         save/cancel actions; Enter/Esc still drive them from the
                         keyboard. -->
                    <template v-if="editingMessage">
                        <Button
                            variant="ghost"
                            size="sm"
                            data-test="message-composer-edit-cancel"
                            class="h-8.5 shrink-0 rounded-full px-3.5 text-[12.5px] font-semibold text-muted-foreground"
                            @click="exitEditMode"
                        >
                            {{ $t('Cancel') }}
                        </Button>
                        <Button
                            size="sm"
                            data-test="message-composer-edit-save"
                            class="h-8.5 shrink-0 rounded-full px-4 text-[12.5px] font-semibold"
                            @click="saveEdit"
                        >
                            {{ $t('Save edit') }}
                        </Button>
                    </template>
                    <template v-else>
                        <input
                            ref="fileInput"
                            type="file"
                            multiple
                            class="hidden"
                            data-test="composer-file-input"
                            @change="onFilesPicked"
                        />
                        <!-- Inline-format cluster: wraps the current selection in
                             Markdown markers, mirrored by the keyboard shortcuts.
                             mousedown is prevented so the textarea keeps focus and
                             its selection survives the click. -->
                        <TooltipProvider
                            :delay-duration="300"
                            :skip-delay-duration="150"
                        >
                            <div
                                class="flex shrink-0 items-center gap-0.5"
                                data-test="composer-format-cluster"
                            >
                                <Tooltip
                                    v-for="action in formatActions"
                                    :key="action.marker"
                                >
                                    <TooltipTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            :data-test="`message-composer-format-${action.marker}`"
                                            class="size-7 shrink-0 rounded-full text-muted-foreground"
                                            :aria-label="action.label"
                                            @mousedown.prevent
                                            @click="applyFormat(action.marker)"
                                        >
                                            <component
                                                :is="action.icon"
                                                class="size-3.5"
                                            />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent
                                        side="top"
                                        :side-offset="6"
                                        class="flex items-center gap-2"
                                    >
                                        {{ action.label }}
                                        <span
                                            class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                                            >{{ action.shortcut }}</span
                                        >
                                    </TooltipContent>
                                </Tooltip>
                            </div>
                        </TooltipProvider>
                        <span
                            class="mx-0.5 h-5 w-px shrink-0 self-center bg-border"
                            aria-hidden="true"
                        ></span>
                        <Button
                            variant="ghost"
                            size="icon"
                            :disabled="!attachmentsEnabled"
                            data-test="message-composer-attach"
                            class="size-7 shrink-0 rounded-full text-muted-foreground"
                            :aria-label="$t('Add attachment')"
                            @click="openFilePicker"
                        >
                            <Plus class="size-3.5" />
                        </Button>
                        <Button
                            v-if="props.allowSchedule"
                            variant="ghost"
                            size="icon"
                            :disabled="body.trim() === ''"
                            data-test="message-composer-schedule"
                            class="size-7 shrink-0 rounded-full text-muted-foreground"
                            :aria-label="$t('Schedule for later')"
                            :title="$t('Schedule for later')"
                            @click="openSchedule"
                        >
                            <CalendarClock class="size-3.5" />
                        </Button>
                        <Button
                            size="icon"
                            :disabled="!canSubmit"
                            data-test="message-composer-send"
                            class="size-8.5 shrink-0 rounded-full bg-primary text-brass hover:bg-primary/90"
                            :aria-label="$t('Send message')"
                            @click="submit"
                        >
                            <ArrowUp class="size-3.75" :stroke-width="2.2" />
                        </Button>
                    </template>
                </div>
            </div>

            <label
                v-if="props.allowSendToChannel && !editingMessage"
                class="mt-2 flex w-fit cursor-pointer items-center gap-1.5 px-1.5 text-[12px] text-muted-foreground select-none"
            >
                <input
                    v-model="sendToChannel"
                    type="checkbox"
                    data-test="send-to-channel"
                    class="size-3.5 rounded border-input accent-primary"
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
