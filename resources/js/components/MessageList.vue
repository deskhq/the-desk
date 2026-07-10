<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    CornerUpLeft,
    Forward,
    MessageSquareText,
    Pencil,
    SmilePlus,
    Trash2,
} from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import EmojiPickerPopover from '@/components/EmojiPickerPopover.vue';
import LinkPreview from '@/components/LinkPreview.vue';
import MessageForward from '@/components/MessageForward.vue';
import MessageQuote from '@/components/MessageQuote.vue';
import MessageReactions from '@/components/MessageReactions.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import UserHoverCard from '@/components/UserHoverCard.vue';
import { useInitials } from '@/composables/useInitials';
import { formatTimeOfDay } from '@/lib/datetime';
import { tokenizeMessageBody } from '@/lib/messageBody';
import type { MessageBodySegment } from '@/lib/messageBody';
import { readersForMessage } from '@/lib/readReceipts';
import { buildTimelineItems } from '@/lib/timeline';
import type { TimelineGroup } from '@/lib/timeline';
import type {
    ChannelReader,
    Mention,
    Message,
    MessageAuthor,
    MessagePreview,
} from '@/types';

const props = defineProps<{
    messages: Message[];
    // The team the messages belong to, so author hover cards can resolve the
    // right member profile.
    teamSlug: string;
    pendingUuids?: string[];
    currentUserId: string;
    canModerate?: boolean;
    // Whether the viewer may add/remove reactions (member of a non-archived
    // channel); existing reaction pills still render read-only when false.
    canReact?: boolean;
    onlineIds?: Set<string>;
    highlightMessageId?: string | null;
    // The message the "New messages" divider sits above — the first unread on
    // channel open — or null when there's no unread boundary to mark.
    unreadDividerId?: string | null;
    // Rendered inside a thread panel: hides the per-message thread affordances
    // (you're already in the thread), so the panel only shows the conversation.
    inThread?: boolean;
    // The root of the currently-open thread, highlighted in the main timeline.
    activeThreadRootId?: string | null;
    // Read positions of channel members who share read receipts, driving the
    // "Seen by" affordance under the newest message. Omitted inside a thread.
    readers?: ChannelReader[];
}>();

const emit = defineEmits<{
    edit: [message: Message, body: string];
    delete: [message: Message];
    reply: [message: Message];
    forward: [message: Message];
    react: [message: Message, emoji: string];
    openThread: [messageId: string];
    jump: [messageId: string];
    mention: [member: { id: string; name: string }];
}>();

// How many participant avatars to preview on a root's "N replies" affordance.
const MAX_THREAD_AVATARS = 3;

function threadAvatars(message: Message): Mention[] {
    return message.threadParticipants.slice(0, MAX_THREAD_AVATARS);
}

function extraThreadParticipants(message: Message): number {
    return Math.max(0, message.threadParticipants.length - MAX_THREAD_AVATARS);
}

/**
 * Inside a thread panel, the root message — the only one with no thread root of
 * its own — earns a brass left accent so it reads as the conversation's origin,
 * setting it apart from the replies below.
 */
function isThreadRoot(item: TimelineGroup): boolean {
    return props.inThread === true && item.messages[0]?.threadRootId === null;
}

// How many reader avatars to preview on the "Seen by" row before collapsing the
// rest into a "+N" overflow chip.
const MAX_SEEN_AVATARS = 3;

// The members who have read up to the newest message, driving the "Seen by" row.
// Empty inside a thread panel and on an empty timeline.
const seenByReaders = computed<MessageAuthor[]>(() => {
    if (props.inThread || props.messages.length === 0) {
        return [];
    }

    const lastMessageId = props.messages[props.messages.length - 1].id;

    return readersForMessage(
        props.readers ?? [],
        lastMessageId,
        props.currentUserId,
    );
});

const seenByAvatars = computed(() =>
    seenByReaders.value.slice(0, MAX_SEEN_AVATARS),
);

const extraSeenBy = computed(() =>
    Math.max(0, seenByReaders.value.length - MAX_SEEN_AVATARS),
);

// A full, human-readable roster ("Seen by Alice, Bob and 3 others") used as the
// row's accessible label and hover title, so the compact avatars still name who.
const seenByLabel = computed(() => {
    const names = seenByReaders.value.map((reader) => reader.name);

    if (names.length === 0) {
        return '';
    }

    if (names.length === 1) {
        return `Seen by ${names[0]}`;
    }

    if (names.length <= MAX_SEEN_AVATARS) {
        return `Seen by ${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
    }

    const shown = names.slice(0, MAX_SEEN_AVATARS).join(', ');
    const others = names.length - MAX_SEEN_AVATARS;

    return `Seen by ${shown} and ${others} ${others === 1 ? 'other' : 'others'}`;
});

const { getInitials } = useInitials();

const page = usePage();

// Render timestamps in the viewer's stored zone, falling back to the browser's.
const viewerTimeZone = computed(
    () => page.props.auth.user.timezone ?? undefined,
);

/**
 * Whether a message author is currently present on the team presence roster.
 */
function isOnline(authorId: string): boolean {
    return props.onlineIds?.has(authorId) ?? false;
}

/**
 * Split a message body into HTML and link segments so the timeline can wrap each
 * URL in its own element (and, when the link has been unfurled, a hover card).
 */
function bodySegments(message: Message): MessageBodySegment[] {
    return tokenizeMessageBody(message.body, message.mentions);
}

/**
 * The unfurled preview for a URL in a message, or undefined when the link has no
 * preview row yet. Pending rows resolve too, so the hover card can show a
 * skeleton until the queued unfurl broadcasts the resolved metadata.
 */
function previewFor(
    message: Message,
    href: string,
): MessagePreview | undefined {
    return message.linkPreviews.find((preview) => preview.url === href);
}

function dividerLabel(iso: string): string {
    const date = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (date.toDateString() === today.toDateString()) {
        return 'Today';
    }

    if (date.toDateString() === yesterday.toDateString()) {
        return 'Yesterday';
    }

    return date.toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year:
            date.getFullYear() === today.getFullYear() ? undefined : 'numeric',
    });
}

function formatTime(iso: string): string {
    return formatTimeOfDay(iso, viewerTimeZone.value);
}

// The grouped, divider-interleaved render list. The grouping and boundary logic
// lives in a pure, unit-tested helper; the day label is formatted here so it
// stays relative to the viewer's "today".
const renderItems = computed(() =>
    buildTimelineItems(props.messages, props.unreadDividerId ?? null),
);

const pending = computed(() => new Set(props.pendingUuids ?? []));

function isPending(message: Message): boolean {
    return pending.value.has(message.clientUuid);
}

function isOwn(message: Message): boolean {
    return message.user.id === props.currentUserId;
}

function canEdit(message: Message): boolean {
    return !message.isDeleted && !isPending(message) && isOwn(message);
}

function canDelete(message: Message): boolean {
    return (
        !message.isDeleted &&
        !isPending(message) &&
        (isOwn(message) || Boolean(props.canModerate))
    );
}

// Anyone can reply to any live message; a pending or deleted row has no stable
// target to quote yet. Inline quoting is a main-timeline affordance — inside a
// thread the composer answers the root, so it's suppressed.
function canReply(message: Message): boolean {
    return !props.inThread && !message.isDeleted && !isPending(message);
}

// Any live message can be forwarded to another channel — including from inside a
// thread panel. A pending or deleted row has no stable target to forward yet.
function canForward(message: Message): boolean {
    return !message.isDeleted && !isPending(message);
}

// The viewer may react to any live, confirmed message when they're a member of
// the (non-archived) channel; a pending or deleted row has no stable target yet.
function canReactTo(message: Message): boolean {
    return Boolean(props.canReact) && !message.isDeleted && !isPending(message);
}

// The hover "reply in thread" action shows on live root messages in the main
// timeline (never on replies inside a panel, nor on messages already in a thread).
function canStartThread(message: Message): boolean {
    return (
        !props.inThread && canReply(message) && message.threadRootId === null
    );
}

// The "N replies" affordance shows on any root that has replies, even a deleted
// one — the thread outlives its root as a tombstone.
function showThreadSummary(message: Message): boolean {
    return !props.inThread && message.threadReplyCount > 0;
}

// The message currently being edited inline, and its working draft.
const editingId = ref<string | null>(null);
const editDraft = ref('');
const editField = ref<HTMLTextAreaElement | null>(null);

// A plain template ref inside v-for collects into an array; a function ref keeps
// the single visible editor element (only one row edits at a time).
function setEditField(el: unknown): void {
    editField.value = (el as HTMLTextAreaElement | null) ?? null;
}

function startEdit(message: Message): void {
    editingId.value = message.id;
    editDraft.value = message.body;
    nextTick(() => editField.value?.focus());
}

function cancelEdit(): void {
    editingId.value = null;
    editDraft.value = '';
}

function saveEdit(message: Message): void {
    const body = editDraft.value.trim();

    // An empty or unchanged draft is a no-op; the server would reject the former.
    if (body !== '' && body !== message.body) {
        emit('edit', message, body);
    }

    cancelEdit();
}

// The message queued for deletion; a non-null value drives the confirm dialog.
const pendingDelete = ref<Message | null>(null);

function requestDelete(message: Message): void {
    pendingDelete.value = message;
}

function setDeleteOpen(open: boolean): void {
    if (!open) {
        pendingDelete.value = null;
    }
}

function confirmDelete(): void {
    if (pendingDelete.value) {
        emit('delete', pendingDelete.value);
    }

    pendingDelete.value = null;
}
</script>

<template>
    <div class="px-5 pt-4 pb-2">
        <div v-for="item in renderItems" :key="item.key" class="contents">
            <div
                v-if="item.type === 'divider' && item.variant === 'unread'"
                id="unread-divider"
                data-test="unread-divider"
                class="my-4 flex items-center gap-3"
            >
                <span
                    aria-hidden="true"
                    class="h-px flex-1 bg-gradient-to-r from-transparent to-brass-border"
                />
                <span class="font-serif text-[13px] text-brass-border italic">
                    new
                </span>
                <span
                    aria-hidden="true"
                    class="h-px flex-1 bg-gradient-to-r from-brass-border to-transparent"
                />
            </div>

            <div
                v-else-if="item.type === 'divider'"
                class="my-4 flex items-center gap-3"
            >
                <span aria-hidden="true" class="h-px flex-1 bg-border" />
                <span
                    class="font-serif text-[13px] text-muted-foreground italic"
                >
                    {{ dividerLabel(item.iso!) }}
                </span>
                <span aria-hidden="true" class="h-px flex-1 bg-border" />
            </div>

            <div v-else class="mt-[18px] flex">
                <div
                    class="flex w-16 shrink-0 flex-col items-center gap-1 pt-0.5"
                >
                    <UserHoverCard
                        :team-slug="props.teamSlug"
                        :user-id="item.author.id"
                        :name="item.author.name"
                        :online="isOnline(item.author.id)"
                        @mention="(member) => emit('mention', member)"
                    >
                        <div class="relative size-[34px] cursor-pointer">
                            <div
                                class="flex size-[34px] items-center justify-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary select-none"
                                aria-hidden="true"
                            >
                                {{ getInitials(item.author.name) }}
                            </div>
                            <span
                                data-test="presence-dot"
                                :data-online="isOnline(item.author.id)"
                                :aria-label="
                                    isOnline(item.author.id)
                                        ? 'Online'
                                        : 'Offline'
                                "
                                class="absolute right-0 bottom-0 size-2.5 rounded-full ring-2 ring-card"
                                :class="
                                    isOnline(item.author.id)
                                        ? 'bg-emerald-500'
                                        : 'bg-muted-foreground/60'
                                "
                            />
                        </div>
                    </UserHoverCard>
                    <span
                        class="font-mono text-[9.5px] text-muted-foreground/70"
                        >{{ formatTime(item.leadCreatedAt) }}</span
                    >
                </div>
                <div
                    class="min-w-0 flex-1 pl-[18px]"
                    :class="
                        isThreadRoot(item)
                            ? 'border-l-2 border-brass'
                            : 'border-l border-border'
                    "
                >
                    <UserHoverCard
                        :team-slug="props.teamSlug"
                        :user-id="item.author.id"
                        :name="item.author.name"
                        :online="isOnline(item.author.id)"
                        @mention="(member) => emit('mention', member)"
                    >
                        <span
                            class="cursor-pointer text-[14px] font-semibold text-foreground hover:underline"
                            >{{ item.author.name }}</span
                        >
                    </UserHoverCard>
                    <div
                        v-for="(message, index) in item.messages"
                        :id="`message-${message.id}`"
                        :key="message.id"
                        class="group/message relative -mx-2 rounded-md px-2 transition-colors duration-1000 hover:bg-muted/40"
                        :class="[
                            index === 0 ? 'mt-0.5' : 'mt-1.5',
                            message.id === props.highlightMessageId
                                ? 'bg-primary/10'
                                : '',
                            message.id === props.activeThreadRootId
                                ? 'bg-primary/5'
                                : '',
                        ]"
                    >
                        <button
                            v-if="
                                message.replyTo &&
                                !message.isDeleted &&
                                editingId !== message.id
                            "
                            type="button"
                            data-test="message-quote"
                            class="mt-0.5 flex max-w-full items-center rounded pr-1 text-left hover:opacity-80"
                            @click="emit('jump', message.replyTo.id)"
                        >
                            <MessageQuote
                                :author-name="message.replyTo.authorName"
                                :body="message.replyTo.body"
                                :is-deleted="message.replyTo.isDeleted"
                            />
                        </button>

                        <p
                            v-if="message.isDeleted"
                            :data-test="'message-tombstone'"
                            class="py-0.5 font-serif text-[13.5px] text-muted-foreground/70 italic"
                        >
                            This message was deleted
                        </p>

                        <div
                            v-else-if="editingId === message.id"
                            class="py-0.5"
                        >
                            <textarea
                                :ref="setEditField"
                                v-model="editDraft"
                                rows="1"
                                class="w-full resize-none rounded-md border border-input bg-background px-2.5 py-1.5 text-[14.5px] leading-[1.55] text-foreground outline-none focus:border-ring focus:ring-1 focus:ring-ring"
                                @keydown.enter.exact.prevent="saveEdit(message)"
                                @keydown.esc.prevent="cancelEdit"
                            ></textarea>
                            <div
                                class="mt-1 flex items-center gap-2 text-[11.5px] text-muted-foreground"
                            >
                                <button
                                    type="button"
                                    class="rounded bg-primary px-2 py-1 font-medium text-primary-foreground hover:bg-primary/90"
                                    @click="saveEdit(message)"
                                >
                                    Save
                                </button>
                                <button
                                    type="button"
                                    class="rounded border border-border px-2 py-1 font-medium text-foreground hover:bg-muted"
                                    @click="cancelEdit"
                                >
                                    Cancel
                                </button>
                                <span>Enter to save · Esc to cancel</span>
                            </div>
                        </div>

                        <p
                            v-else-if="message.body !== ''"
                            :data-test="'message-body'"
                            class="py-0.5 text-[14.5px] leading-[1.55] break-words whitespace-pre-wrap text-foreground/90"
                            :class="isPending(message) ? 'opacity-60' : ''"
                        >
                            <template
                                v-for="(segment, index) in bodySegments(
                                    message,
                                )"
                                :key="index"
                            >
                                <span
                                    v-if="segment.kind === 'html'"
                                    v-html="segment.html"
                                ></span>
                                <UserHoverCard
                                    v-else-if="segment.kind === 'mention'"
                                    :team-slug="props.teamSlug"
                                    :user-id="segment.id"
                                    :name="segment.name"
                                    @mention="
                                        (member) => emit('mention', member)
                                    "
                                >
                                    <span
                                        data-test="message-mention"
                                        class="cursor-pointer border-b-[1.5px] border-brass font-medium text-foreground hover:border-brass-border"
                                        >@{{ segment.name }}</span
                                    >
                                </UserHoverCard>
                                <template v-else>
                                    <HoverCard
                                        v-if="previewFor(message, segment.href)"
                                        :open-delay="200"
                                        :close-delay="100"
                                    >
                                        <HoverCardTrigger as-child>
                                            <a
                                                :href="segment.href"
                                                target="_blank"
                                                rel="noopener noreferrer nofollow"
                                                data-test="message-link"
                                                class="text-primary underline underline-offset-2 hover:no-underline"
                                                >{{ segment.href }}</a
                                            >
                                        </HoverCardTrigger>
                                        <HoverCardContent
                                            data-test="link-preview-card"
                                            class="w-80 overflow-hidden p-0"
                                        >
                                            <LinkPreview
                                                :preview="
                                                    previewFor(
                                                        message,
                                                        segment.href,
                                                    )!
                                                "
                                            />
                                        </HoverCardContent>
                                    </HoverCard>
                                    <a
                                        v-else
                                        :href="segment.href"
                                        target="_blank"
                                        rel="noopener noreferrer nofollow"
                                        data-test="message-link"
                                        class="text-primary underline underline-offset-2 hover:no-underline"
                                        >{{ segment.href }}</a
                                    >
                                </template>
                            </template>
                            <span
                                v-if="message.editedAt"
                                :data-test="'message-edited'"
                                class="ml-1 align-baseline text-[11px] text-muted-foreground/70 select-none"
                                >(edited)</span
                            >
                        </p>

                        <MessageForward
                            v-if="
                                message.forwardedFrom &&
                                !message.isDeleted &&
                                editingId !== message.id
                            "
                            data-test="forwarded-message"
                            :author-name="message.forwardedFrom.authorName"
                            :channel-name="message.forwardedFrom.channelName"
                            :body="message.forwardedFrom.body"
                            :is-deleted="message.forwardedFrom.isDeleted"
                            :mentions="message.forwardedFrom.mentions"
                        />

                        <MessageReactions
                            v-if="
                                !message.isDeleted && editingId !== message.id
                            "
                            :reactions="message.reactions"
                            :current-user-id="props.currentUserId"
                            :can-react="canReactTo(message)"
                            @toggle="(emoji) => emit('react', message, emoji)"
                        />

                        <div
                            v-if="showThreadSummary(message)"
                            class="mt-1.5 flex flex-wrap items-center gap-2"
                        >
                            <button
                                type="button"
                                data-test="thread-summary"
                                :aria-label="`View thread, ${message.threadReplyCount} ${message.threadReplyCount === 1 ? 'reply' : 'replies'}`"
                                class="inline-flex items-center gap-2 rounded-full border border-border bg-card px-2.5 py-1 text-left transition-colors hover:bg-muted/50"
                                @click="emit('openThread', message.id)"
                            >
                                <span class="flex -space-x-1">
                                    <span
                                        v-for="participant in threadAvatars(
                                            message,
                                        )"
                                        :key="participant.id"
                                        class="flex size-4 items-center justify-center rounded-full bg-primary/10 text-[8px] font-semibold text-primary ring-2 ring-card select-none"
                                        aria-hidden="true"
                                    >
                                        {{ getInitials(participant.name) }}
                                    </span>
                                    <span
                                        v-if="
                                            extraThreadParticipants(message) > 0
                                        "
                                        class="flex size-4 items-center justify-center rounded-full bg-muted text-[8px] font-semibold text-muted-foreground ring-2 ring-card select-none"
                                        aria-hidden="true"
                                    >
                                        +{{ extraThreadParticipants(message) }}
                                    </span>
                                </span>
                                <span
                                    v-if="message.threadUnread"
                                    data-test="thread-unread-dot"
                                    aria-label="Unread replies"
                                    class="size-2 shrink-0 rounded-full bg-rose-500"
                                ></span>
                                <span
                                    class="text-[12px] font-semibold text-foreground"
                                >
                                    {{ message.threadReplyCount }}
                                    {{
                                        message.threadReplyCount === 1
                                            ? 'reply'
                                            : 'replies'
                                    }}
                                </span>
                                <span
                                    aria-hidden="true"
                                    class="text-[12px] text-muted-foreground"
                                    >→</span
                                >
                            </button>
                            <span
                                v-if="message.threadLastReplyAt"
                                class="text-[11.5px] text-muted-foreground"
                            >
                                Last reply
                                {{ formatTime(message.threadLastReplyAt) }}
                            </span>
                        </div>

                        <div
                            v-if="
                                editingId !== message.id &&
                                (canReactTo(message) ||
                                    canReply(message) ||
                                    canStartThread(message) ||
                                    canForward(message) ||
                                    canEdit(message) ||
                                    canDelete(message))
                            "
                            class="absolute -top-3 right-2 hidden items-center gap-0.5 rounded-md border border-border bg-background p-0.5 shadow-sm group-hover/message:flex has-[[data-state=open]]:flex"
                        >
                            <EmojiPickerPopover
                                v-if="canReactTo(message)"
                                @select="
                                    (emoji) => emit('react', message, emoji)
                                "
                            >
                                <button
                                    type="button"
                                    data-test="message-react"
                                    aria-label="Add reaction"
                                    class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                >
                                    <SmilePlus class="size-3.5" />
                                </button>
                            </EmojiPickerPopover>
                            <button
                                v-if="canStartThread(message)"
                                type="button"
                                data-test="message-thread"
                                aria-label="Reply in thread"
                                class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                @click="emit('openThread', message.id)"
                            >
                                <MessageSquareText class="size-3.5" />
                            </button>
                            <button
                                v-if="canReply(message)"
                                type="button"
                                :data-test="'message-reply'"
                                aria-label="Reply to message"
                                class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                @click="emit('reply', message)"
                            >
                                <CornerUpLeft class="size-3.5" />
                            </button>
                            <button
                                v-if="canForward(message)"
                                type="button"
                                :data-test="'message-forward'"
                                aria-label="Forward message"
                                class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                @click="emit('forward', message)"
                            >
                                <Forward class="size-3.5" />
                            </button>
                            <button
                                v-if="canEdit(message)"
                                type="button"
                                :data-test="'message-edit'"
                                aria-label="Edit message"
                                class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                @click="startEdit(message)"
                            >
                                <Pencil class="size-3.5" />
                            </button>
                            <button
                                v-if="canDelete(message)"
                                type="button"
                                :data-test="'message-delete'"
                                aria-label="Delete message"
                                class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                                @click="requestDelete(message)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="seenByReaders.length > 0"
            data-test="seen-by"
            class="mt-1.5 flex items-center justify-end gap-1.5 pr-1"
            :title="seenByLabel"
        >
            <span class="font-serif text-[11px] text-muted-foreground italic">
                Seen by
            </span>
            <span class="flex -space-x-1">
                <span
                    v-for="reader in seenByAvatars"
                    :key="reader.id"
                    class="flex size-4 items-center justify-center rounded-full bg-primary/10 text-[8px] font-semibold text-primary ring-2 ring-card select-none"
                    aria-hidden="true"
                >
                    {{ getInitials(reader.name) }}
                </span>
                <span
                    v-if="extraSeenBy > 0"
                    class="flex size-4 items-center justify-center rounded-full bg-muted text-[8px] font-semibold text-muted-foreground ring-2 ring-card select-none"
                    aria-hidden="true"
                >
                    +{{ extraSeenBy }}
                </span>
            </span>
            <span class="sr-only">{{ seenByLabel }}</span>
        </div>

        <Dialog :open="pendingDelete !== null" @update:open="setDeleteOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete message</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to delete this message? This can't
                        be undone.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary"> Cancel </Button>
                    </DialogClose>

                    <Button
                        data-test="delete-message-confirm"
                        variant="destructive"
                        @click="confirmDelete"
                    >
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
