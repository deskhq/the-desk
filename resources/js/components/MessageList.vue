<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MessageActionsSheet from '@/components/MessageActionsSheet.vue';
import DayDivider from '@/components/timeline/DayDivider.vue';
import DeleteMessageDialog from '@/components/timeline/DeleteMessageDialog.vue';
import MessageAuthorLine from '@/components/timeline/MessageAuthorLine.vue';
import MessageAvatarGutter from '@/components/timeline/MessageAvatarGutter.vue';
import MessageRow from '@/components/timeline/MessageRow.vue';
import MessageSkeleton from '@/components/timeline/MessageSkeleton.vue';
import SeenByRow from '@/components/timeline/SeenByRow.vue';
import SystemNotice from '@/components/timeline/SystemNotice.vue';
import ThreadRepliesDivider from '@/components/timeline/ThreadRepliesDivider.vue';
import UnreadDivider from '@/components/timeline/UnreadDivider.vue';
import { useIsMobile } from '@/composables/useIsMobile';
import { useMessageActionSheet } from '@/composables/useMessageActionSheet';
import { useTimelineWindow } from '@/composables/useTimelineWindow';
import { displayAuthorName } from '@/lib/authorIdentity';
import { formatTimeOfDay } from '@/lib/datetime';
import { hasAnyMessageAction } from '@/lib/messageActions';
import type { RenderedPresence } from '@/lib/presence';
import { readersForMessage } from '@/lib/readReceipts';
import { buildTimelineItems } from '@/lib/timeline';
import type { TimelineGroup } from '@/lib/timeline';
import type { ChannelReader, Message, MessageAuthor } from '@/types';

const props = defineProps<{
    messages: Message[];
    /**
     * The team the messages belong to, so author hover cards can resolve the
     * right member profile.
     */
    teamSlug: string;
    pendingUuids?: string[];
    /**
     * Client uuids of the viewer's own sends held in the offline outbox; each
     * renders a "Queued — will send on reconnect" marker until it flushes.
     */
    queuedUuids?: string[];
    currentUserId: string;
    /**
     * Whether the timeline belongs to a direct message, so a "member left" notice
     * reads "left the conversation" rather than "left the channel".
     */
    isDirect?: boolean;
    canModerate?: boolean;
    /**
     * Whether the viewer may add/remove reactions (member of a non-archived
     * channel); existing reaction pills still render read-only when false.
     */
    canReact?: boolean;
    /**
     * Whether the viewer may pin/unpin messages (member of a non-archived
     * channel); the "Pinned by" indicator still renders read-only when false.
     */
    canPin?: boolean;
    /**
     * How each author reads on the team presence roster. Absent on the surfaces
     * that render a timeline without one, where every author reads as offline.
     */
    presenceFor?: (userId: string) => RenderedPresence;
    /**
     * Whether each author is in do-not-disturb, driving the crescent badge on
     * their dot. Absent on the same surfaces that carry no roster.
     */
    isDndFor?: (userId: string) => boolean;
    highlightMessageId?: string | null;
    /**
     * The message the "New messages" divider sits above — the first unread on
     * channel open — or null when there's no unread boundary to mark.
     */
    unreadDividerId?: string | null;
    /**
     * Rendered inside a thread panel: hides the per-message thread affordances
     * (you're already in the thread), so the panel only shows the conversation.
     */
    inThread?: boolean;
    /**
     * When set (> 0) inside a thread, a ":count replies" rule renders under the
     * root message, separating it from the replies — the mobile thread push's
     * treatment, where the panel header no longer carries the count.
     */
    replyDividerCount?: number;
    /** The root of the currently-open thread, highlighted in the main timeline. */
    activeThreadRootId?: string | null;
    /**
     * The message currently being edited from the composer, brass-highlighted so
     * the edit target is unmistakable while the composer holds its text.
     */
    editingMessageId?: string | null;
    /**
     * Read positions of channel members who share read receipts, driving the
     * "Seen by" affordance under the newest message. Omitted inside a thread.
     */
    readers?: ChannelReader[];
    /**
     * Opt into windowed (virtualized) rendering. The main channel timeline sets
     * this so only on-screen rows mount; the thread panel leaves it off and
     * renders the full list.
     */
    virtualize?: boolean;
    /**
     * The parent-owned scroll container the virtualizer drives. Required when
     * `virtualize` is set; the virtualizer reads its scroll offset and height.
     */
    scrollElement?: HTMLElement | null;
    /**
     * Whether older history remains to fetch, and whether a fetch is already in
     * flight — read by the virtualizer to gate its top-load trigger. Supplied by
     * the parent, which owns Inertia's `<InfiniteScroll>` merge engine.
     */
    hasOlder?: () => boolean;
    isLoadingOlder?: () => boolean;
}>();

const emit = defineEmits<{
    edit: [message: Message, body: string];
    delete: [message: Message];
    reply: [message: Message];
    forward: [message: Message];
    react: [message: Message, emoji: string];
    vote: [message: Message, optionId: string];
    closePoll: [message: Message];
    pin: [message: Message];
    unpin: [message: Message];
    remind: [message: Message, remindAt: string];
    remindCustom: [message: Message];
    openThread: [messageId: string];
    jump: [messageId: string];
    mention: [member: { id: string; name: string }];
    /** The reader has scrolled near the top of the loaded history: fetch older. */
    loadOlder: [];
    /**
     * The virtualizer's visible render-item window changed, so the parent can
     * recompute position-dependent affordances (the unread-jump pill).
     */
    rangeChange: [range: { startIndex: number; endIndex: number }];
}>();

const page = usePage();

/** The viewer's stored zone, feeding the reminder popover's wall-clock presets. */
const viewerTimezone = computed<string | null>(
    () => page.props.auth.user.timezone ?? null,
);

/** Render timestamps in the viewer's stored zone, falling back to the browser's. */
const viewerTimeZone = computed(
    () => page.props.auth.user.timezone ?? undefined,
);

function formatTime(iso: string): string {
    return formatTimeOfDay(iso, viewerTimeZone.value);
}

/**
 * Inside a thread panel, the root message — the only one with no thread root of
 * its own — earns a brass left accent so it reads as the conversation's origin,
 * setting it apart from the replies below.
 */
function isThreadRoot(item: TimelineGroup): boolean {
    return props.inThread === true && item.messages[0]?.threadRootId === null;
}

/**
 * The members who have read up to the newest message, driving the "Seen by" row.
 * Empty inside a thread panel and on an empty timeline.
 */
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

/**
 * How a message author reads on the team presence roster.
 */
function presenceOf(authorId: string): RenderedPresence {
    return props.presenceFor?.(authorId) ?? 'offline';
}

/**
 * Whether a message author shows the crescent DND badge on their dot.
 */
function dndOf(authorId: string): boolean {
    return props.isDndFor?.(authorId) ?? false;
}

/**
 * The grouped, divider-interleaved render list. The grouping and boundary logic
 * lives in a pure, unit-tested helper; the day label is formatted here so it
 * stays relative to the viewer's "today".
 */
const renderItems = computed(() =>
    buildTimelineItems(props.messages, props.unreadDividerId ?? null),
);

const {
    virtualizeActive,
    totalSize,
    renderRows,
    measureRow,
    showsSkeleton,
    scrollToIndex,
    scrollToLatest,
} = useTimelineWindow({
    scrollElement: () => props.scrollElement ?? null,
    renderItems,
    virtualize: () => props.virtualize === true,
    hasOlder: () => props.hasOlder?.() ?? false,
    isLoadingOlder: () => props.isLoadingOlder?.() ?? false,
    loadOlder: () => emit('loadOlder'),
    onRangeChange: (range) => emit('rangeChange', range),
});

// Let the parent bring an off-screen row (a jump target, the unread boundary)
// into the window: with windowing the element may not exist to `scrollIntoView`.
// `scrollToLatest` drives the reliable jump-to-present for the windowed timeline.
defineExpose({ scrollToIndex, scrollToLatest });

const pending = computed(() => new Set(props.pendingUuids ?? []));

function isPending(message: Message): boolean {
    return pending.value.has(message.clientUuid);
}

const queued = computed(() => new Set(props.queuedUuids ?? []));

function isQueued(message: Message): boolean {
    return queued.value.has(message.clientUuid);
}

/** The message currently being edited inline. */
const editingId = ref<string | null>(null);

function startEdit(message: Message): void {
    editingId.value = message.id;
}

function cancelEdit(): void {
    editingId.value = null;
}

function saveEdit(message: Message, draft: string): void {
    const body = draft.trim();

    // An empty or unchanged draft is a no-op; the server would reject the former.
    if (body !== '' && body !== message.body) {
        emit('edit', message, body);
    }

    cancelEdit();
}

const isMobile = useIsMobile();

const {
    open: actionSheetOpen,
    message: actionSheetMessage,
    longPress,
    isHeld,
} = useMessageActionSheet({
    isMobile,
    messages: () => props.messages,
    canOpen: (message) =>
        editingId.value !== message.id &&
        hasAnyMessageAction(message, {
            currentUserId: props.currentUserId,
            canReact: Boolean(props.canReact),
            canPin: Boolean(props.canPin),
            canModerate: Boolean(props.canModerate),
            inThread: Boolean(props.inThread),
            pending: isPending(message),
        }),
});

/** The message queued for deletion; a non-null value drives the confirm dialog. */
const pendingDelete = ref<Message | null>(null);

function requestDelete(message: Message): void {
    pendingDelete.value = message;
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
        <div
            :class="virtualizeActive ? 'relative w-full' : 'contents'"
            :style="virtualizeActive ? { height: `${totalSize}px` } : undefined"
        >
            <div
                v-for="{ item, index, offsetTop } in renderRows"
                :key="item.key"
                :ref="virtualizeActive ? measureRow : undefined"
                :data-index="index"
                :class="virtualizeActive ? '' : 'contents'"
                :style="
                    virtualizeActive
                        ? {
                              position: 'absolute',
                              top: '0',
                              left: '0',
                              width: '100%',
                              transform: `translateY(${offsetTop}px)`,
                          }
                        : undefined
                "
            >
                <MessageSkeleton v-if="showsSkeleton(item)" />

                <UnreadDivider
                    v-else-if="
                        item.type === 'divider' && item.variant === 'unread'
                    "
                />

                <DayDivider
                    v-else-if="item.type === 'divider'"
                    :iso="item.iso!"
                />

                <SystemNotice
                    v-else-if="item.type === 'system'"
                    :message="item.message"
                    :is-direct="props.isDirect"
                />

                <div
                    v-else
                    data-test="message-group"
                    class="mt-4.5 flex max-md:mt-3.5"
                >
                    <MessageAvatarGutter
                        :author="item.author"
                        :author-override="item.authorOverride"
                        :team-slug="props.teamSlug"
                        :presence="presenceOf(item.author.id)"
                        :is-dnd="dndOf(item.author.id)"
                        :time="formatTime(item.leadCreatedAt)"
                        :is-mobile="isMobile"
                        @mention="(member) => emit('mention', member)"
                    />
                    <div
                        data-test="message-column"
                        class="min-w-0 flex-1 pl-4.5 max-md:border-l-0 max-md:pl-0"
                        :class="
                            isThreadRoot(item)
                                ? 'border-l-2 border-brass'
                                : 'border-l border-border'
                        "
                    >
                        <MessageAuthorLine
                            :author="item.author"
                            :author-override="item.authorOverride"
                            :team-slug="props.teamSlug"
                            :presence="presenceOf(item.author.id)"
                            :is-dnd="dndOf(item.author.id)"
                            :time="formatTime(item.leadCreatedAt)"
                            @mention="(member) => emit('mention', member)"
                        />
                        <div role="list">
                            <MessageRow
                                v-for="(message, row) in item.messages"
                                :key="message.id"
                                :message="message"
                                :author-name="
                                    displayAuthorName(
                                        item.author.name,
                                        item.authorOverride,
                                    )
                                "
                                :team-slug="props.teamSlug"
                                :current-user-id="props.currentUserId"
                                :can-react="props.canReact"
                                :can-pin="props.canPin"
                                :can-moderate="props.canModerate"
                                :in-thread="props.inThread"
                                :is-lead="row === 0"
                                :pending="isPending(message)"
                                :queued="isQueued(message)"
                                :held="isHeld(message)"
                                :editing="editingId === message.id"
                                :highlighted="
                                    message.id === props.highlightMessageId
                                "
                                :active-thread-root="
                                    message.id === props.activeThreadRootId
                                "
                                :composer-editing="
                                    message.id === props.editingMessageId
                                "
                                :viewer-time-zone="viewerTimeZone"
                                :viewer-timezone="viewerTimezone"
                                :long-press="longPress"
                                @start-edit="startEdit(message)"
                                @save-edit="(body) => saveEdit(message, body)"
                                @cancel-edit="cancelEdit"
                                @request-delete="requestDelete(message)"
                                @reply="emit('reply', message)"
                                @forward="emit('forward', message)"
                                @pin="emit('pin', message)"
                                @unpin="emit('unpin', message)"
                                @close-poll="emit('closePoll', message)"
                                @remind-custom="emit('remindCustom', message)"
                                @open-thread="(id) => emit('openThread', id)"
                                @react="
                                    (emoji) => emit('react', message, emoji)
                                "
                                @vote="
                                    (optionId) =>
                                        emit('vote', message, optionId)
                                "
                                @remind="
                                    (remindAt) =>
                                        emit('remind', message, remindAt)
                                "
                                @jump="(id) => emit('jump', id)"
                                @mention="(member) => emit('mention', member)"
                            />
                        </div>
                    </div>
                </div>

                <ThreadRepliesDivider
                    v-if="
                        item.type === 'group' &&
                        isThreadRoot(item) &&
                        (props.replyDividerCount ?? 0) > 0
                    "
                    :count="props.replyDividerCount ?? 0"
                />
            </div>
        </div>

        <SeenByRow v-if="seenByReaders.length > 0" :readers="seenByReaders" />

        <DeleteMessageDialog
            :open="pendingDelete !== null"
            @confirm="confirmDelete"
            @close="pendingDelete = null"
        />

        <MessageActionsSheet
            v-model:open="actionSheetOpen"
            :message="actionSheetMessage"
            :current-user-id="props.currentUserId"
            :can-react="props.canReact"
            :can-pin="props.canPin"
            :can-moderate="props.canModerate"
            :in-thread="props.inThread"
            :pending="
                actionSheetMessage ? isPending(actionSheetMessage) : false
            "
            :viewer-time-zone="viewerTimeZone"
            @react="
                (emoji) =>
                    actionSheetMessage &&
                    emit('react', actionSheetMessage, emoji)
            "
            @open-thread="
                actionSheetMessage && emit('openThread', actionSheetMessage.id)
            "
            @reply="actionSheetMessage && emit('reply', actionSheetMessage)"
            @forward="actionSheetMessage && emit('forward', actionSheetMessage)"
            @pin="actionSheetMessage && emit('pin', actionSheetMessage)"
            @unpin="actionSheetMessage && emit('unpin', actionSheetMessage)"
            @remind-custom="
                actionSheetMessage && emit('remindCustom', actionSheetMessage)
            "
            @edit="actionSheetMessage && startEdit(actionSheetMessage)"
            @delete="actionSheetMessage && requestDelete(actionSheetMessage)"
        />
    </div>
</template>
