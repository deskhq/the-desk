<script setup lang="ts">
import { ArrowUp, CalendarClock, Pencil, Plus, X } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import MessageQuote from '@/components/MessageQuote.vue';
import ScheduleMessageDialog from '@/components/ScheduleMessageDialog.vue';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/composables/useInitials';
import { useTranslations } from '@/composables/useTranslations';
import {
    isComposerEditTrigger,
    resolveComposerEditTarget,
} from '@/lib/composerEdit';
import { isInteractiveComposerTarget } from '@/lib/composerFocus';
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
}>();

const emit = defineEmits<{
    send: [body: string, mentions: Mention[], sendToChannel?: boolean];
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

defineExpose({ insertMention, focus });

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
    const trimmed = body.value.trim();

    if (trimmed === '') {
        return;
    }

    emit('send', trimmed, collectMentions(trimmed), sendToChannel.value);
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
                <button
                    type="button"
                    data-test="reply-preview-dismiss"
                    :aria-label="$t('Cancel reply')"
                    class="shrink-0 rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                    @click="emit('cancelReply')"
                >
                    <X class="size-3.5" />
                </button>
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
                <button
                    type="button"
                    data-test="composer-editing-dismiss"
                    :aria-label="$t('Cancel edit')"
                    class="shrink-0 rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                    @click="exitEditMode"
                >
                    <X class="size-3.5" />
                </button>
            </div>

            <!-- Floating pill: input on the left, ghost tool icons and the ink
                 send circle tucked to the right. Grows upward as the textarea
                 wraps, with the tools pinned to the bottom edge. -->
            <div
                class="flex items-end gap-2.5 rounded-[26px] border bg-card py-2 pr-2 pl-4.5 shadow-[0_3px_12px_rgba(29,26,21,0.08)] dark:shadow-[0_3px_12px_rgba(0,0,0,0.3)]"
                :class="
                    editingMessage
                        ? 'border-brass ring-[3px] ring-brass/20'
                        : 'border-input focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/20'
                "
                @mousedown="focusFromCard"
            >
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
                    :aria-controls="showMenu ? 'mention-listbox' : undefined"
                    :aria-activedescendant="
                        showMenu ? `mention-option-${activeIndex}` : undefined
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
                    @input="(resize(), refreshSuggestions(), emit('typing'))"
                    @click="refreshSuggestions"
                    @keydown="onKeydown"
                ></textarea>
                <!-- Edit mode swaps the compose tools for explicit save/cancel
                     actions; Enter/Esc still drive them from the keyboard. -->
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
                    <Button
                        variant="ghost"
                        size="icon"
                        disabled
                        class="size-7 shrink-0 rounded-full text-muted-foreground"
                        :aria-label="$t('Add attachment')"
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
                        :disabled="body.trim() === ''"
                        data-test="message-composer-send"
                        class="size-8.5 shrink-0 rounded-full bg-primary text-brass hover:bg-primary/90"
                        :aria-label="$t('Send message')"
                        @click="submit"
                    >
                        <ArrowUp class="size-3.75" :stroke-width="2.2" />
                    </Button>
                </template>
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
