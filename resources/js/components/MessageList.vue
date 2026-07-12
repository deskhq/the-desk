<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Clock } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import LinkPreview from '@/components/LinkPreview.vue';
import MessageActions from '@/components/MessageActions.vue';
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
import { useCustomEmojis } from '@/composables/useCustomEmojis';
import { useInitials } from '@/composables/useInitials';
import { useTimelineVirtualizer } from '@/composables/useTimelineVirtualizer';
import { useTranslations } from '@/composables/useTranslations';
import { formatDayLabel, formatTimeOfDay } from '@/lib/datetime';
import { canReactToMessage, showsThreadSummary } from '@/lib/messageActions';
import type { MessageActionContext } from '@/lib/messageActions';
import { tokenizeMessageBody } from '@/lib/messageBody';
import type { MessageBodySegment } from '@/lib/messageBody';
import { readersForMessage } from '@/lib/readReceipts';
import { buildTimelineItems, messageAccessibleName } from '@/lib/timeline';
import type { TimelineGroup, TimelineItem } from '@/lib/timeline';
import { shouldRenderSkeleton } from '@/lib/virtualTimeline';
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
    // Client uuids of the viewer's own sends held in the offline outbox; each
    // renders a "Queued — will send on reconnect" marker until it flushes.
    queuedUuids?: string[];
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
    // Opt into windowed (virtualized) rendering. The main channel timeline sets
    // this so only on-screen rows mount; the thread panel leaves it off and
    // renders the full list.
    virtualize?: boolean;
    // The parent-owned scroll container the virtualizer drives. Required when
    // `virtualize` is set; the virtualizer reads its scroll offset and height.
    scrollElement?: HTMLElement | null;
    // Whether older history remains to fetch, and whether a fetch is already in
    // flight — read by the virtualizer to gate its top-load trigger. Supplied by
    // the parent, which owns Inertia's `<InfiniteScroll>` merge engine.
    hasOlder?: () => boolean;
    isLoadingOlder?: () => boolean;
}>();

const emit = defineEmits<{
    edit: [message: Message, body: string];
    delete: [message: Message];
    reply: [message: Message];
    forward: [message: Message];
    react: [message: Message, emoji: string];
    remind: [message: Message, remindAt: string];
    remindCustom: [message: Message];
    openThread: [messageId: string];
    jump: [messageId: string];
    mention: [member: { id: string; name: string }];
    // The reader has scrolled near the top of the loaded history: fetch older.
    loadOlder: [];
    // The virtualizer's visible render-item window changed, so the parent can
    // recompute position-dependent affordances (the unread-jump pill).
    rangeChange: [range: { startIndex: number; endIndex: number }];
}>();

// The viewer's stored zone, feeding the reminder popover's wall-clock presets.
const viewerTimezone = computed<string | null>(
    () => page.props.auth.user.timezone ?? null,
);

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
        return t('Seen by :name', { name: names[0] });
    }

    if (names.length <= MAX_SEEN_AVATARS) {
        return t('Seen by :names and :last', {
            names: names.slice(0, -1).join(', '),
            last: names[names.length - 1],
        });
    }

    const shown = names.slice(0, MAX_SEEN_AVATARS).join(', ');
    const others = names.length - MAX_SEEN_AVATARS;

    return others === 1
        ? t('Seen by :names and :count other', { names: shown, count: others })
        : t('Seen by :names and :count others', {
              names: shown,
              count: others,
          });
});

const { getInitials } = useInitials();

const { t } = useTranslations();

const { map: customEmojis } = useCustomEmojis();

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
    return tokenizeMessageBody(
        message.body,
        message.mentions,
        customEmojis.value,
    );
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

function formatTime(iso: string): string {
    return formatTimeOfDay(iso, viewerTimeZone.value);
}

// The grouped, divider-interleaved render list. The grouping and boundary logic
// lives in a pure, unit-tested helper; the day label is formatted here so it
// stays relative to the viewer's "today".
const renderItems = computed(() =>
    buildTimelineItems(props.messages, props.unreadDividerId ?? null),
);

// Windowed rendering. Only the main channel timeline opts in (`virtualize`); the
// thread panel leaves the virtualizer idle (count 0) and renders every row. The
// virtualizer drives the parent's scroll container, keeping its real
// `scrollHeight`/`scrollTop` so the shared pin-to-bottom math is untouched.
// Windowing is client-only: the virtualizer needs a live scroll element and
// measured heights, neither of which exist during SSR. Rendering the full list
// on the server (and through first hydration) keeps server and client markup
// identical, then we switch to the window once mounted — a post-hydration
// re-render, not a mismatch.
const isMounted = ref(false);

onMounted(() => {
    isMounted.value = true;
});

const virtualizeActive = computed(
    () => props.virtualize === true && isMounted.value,
);

const {
    virtualRows,
    totalSize,
    isScrolling,
    range,
    scrollToIndex,
    measureRow,
} = useTimelineVirtualizer({
    scrollElement: computed(() => props.scrollElement ?? null),
    count: computed(() =>
        virtualizeActive.value ? renderItems.value.length : 0,
    ),
    hasOlder: () => props.hasOlder?.() ?? false,
    isLoadingOlder: () => props.isLoadingOlder?.() ?? false,
    loadOlder: () => emit('loadOlder'),
});

/** One rendered row: a render item plus its absolute offset when windowed. */
type RenderRow = {
    item: TimelineItem;
    index: number;
    offsetTop: number | null;
};

// The rows to render: the full list when the thread panel renders it flat, or
// just the virtualizer's window (each carrying its absolute offset) for the main
// timeline.
const renderRows = computed<RenderRow[]>(() => {
    if (!virtualizeActive.value) {
        return renderItems.value.map((item, index) => ({
            item,
            index,
            offsetTop: null,
        }));
    }

    return virtualRows.value.map((row) => ({
        item: renderItems.value[row.index],
        index: row.index,
        offsetTop: row.start,
    }));
});

// Render items whose height the virtualizer has already settled. A row is
// recorded once scrolling stops, so a fast scrub shows height-stable skeletons
// for rows it hasn't paused on, and they materialize the instant it settles.
const settledKeys = ref<Set<string>>(new Set());

watch(isScrolling, (scrolling) => {
    if (scrolling) {
        return;
    }

    for (const row of virtualRows.value) {
        const item = renderItems.value[row.index];

        if (item) {
            settledKeys.value.add(item.key);
        }
    }
});

/**
 * Whether a windowed group row should show its skeleton placeholder rather than
 * its full content: only mid-scrub, and only before the row has settled.
 */
function showsSkeleton(item: TimelineItem): boolean {
    return (
        virtualizeActive.value &&
        item.type === 'group' &&
        shouldRenderSkeleton(isScrolling.value, settledKeys.value.has(item.key))
    );
}

// Surface the visible window so the parent can drive the unread-jump pill.
watch(
    () =>
        range.value
            ? `${range.value.startIndex}:${range.value.endIndex}`
            : null,
    () => {
        if (range.value) {
            emit('rangeChange', {
                startIndex: range.value.startIndex,
                endIndex: range.value.endIndex,
            });
        }
    },
);

// Let the parent bring an off-screen row (a jump target, the unread boundary)
// into the window: with windowing the element may not exist to `scrollIntoView`.
defineExpose({ scrollToIndex });

const pending = computed(() => new Set(props.pendingUuids ?? []));

function isPending(message: Message): boolean {
    return pending.value.has(message.clientUuid);
}

const queued = computed(() => new Set(props.queuedUuids ?? []));

function isQueued(message: Message): boolean {
    return queued.value.has(message.clientUuid);
}

// The viewer context each per-message guard resolves against. The hover-action
// bar (extracted to MessageActions) owns the toolbar guards; the two rules below
// stay here because they drive non-toolbar UI — the reaction pills and the
// thread summary — and share the same context.
function actionContext(message: Message): MessageActionContext {
    return {
        currentUserId: props.currentUserId,
        canReact: Boolean(props.canReact),
        canModerate: Boolean(props.canModerate),
        inThread: Boolean(props.inThread),
        pending: isPending(message),
    };
}

// The viewer may react to any live, confirmed message when they're a member of
// the (non-archived) channel; drives whether the reaction pills accept toggles.
function canReactTo(message: Message): boolean {
    return canReactToMessage(message, actionContext(message));
}

// The "N replies" affordance shows on any root that has replies, even a deleted
// one — the thread outlives its root as a tombstone.
function showThreadSummary(message: Message): boolean {
    return showsThreadSummary(message, actionContext(message));
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
                <div
                    v-if="showsSkeleton(item)"
                    data-test="message-skeleton"
                    aria-hidden="true"
                    class="mt-[18px] flex gap-3"
                    :style="{ minHeight: '56px' }"
                >
                    <div class="size-[34px] shrink-0 rounded-full bg-muted" />
                    <div class="flex flex-1 flex-col gap-2 pt-1">
                        <div class="h-3 w-40 rounded bg-muted" />
                        <div class="h-3 w-[60%] rounded bg-muted" />
                    </div>
                </div>

                <div
                    v-else-if="
                        item.type === 'divider' && item.variant === 'unread'
                    "
                    id="unread-divider"
                    data-test="unread-divider"
                    role="separator"
                    :aria-label="$t('New messages')"
                    class="my-4 flex items-center gap-3"
                >
                    <span
                        aria-hidden="true"
                        class="h-px flex-1 bg-gradient-to-r from-transparent to-brass-border"
                    />
                    <span
                        aria-hidden="true"
                        class="font-serif text-[13px] text-brass-border italic"
                    >
                        {{ $t('new') }}
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
                        {{ formatDayLabel(item.iso!) }}
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
                                <!-- Presence is announced once per author group
                                     via the sr-only text beside the name below,
                                     so this repeated dot is decorative to AT. -->
                                <span
                                    data-test="presence-dot"
                                    :data-online="isOnline(item.author.id)"
                                    aria-hidden="true"
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
                            class="font-mono text-[9.5px] text-muted-foreground"
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
                        <span class="sr-only">{{
                            isOnline(item.author.id)
                                ? $t('Online')
                                : $t('Offline')
                        }}</span>
                        <div role="list">
                            <div
                                v-for="(message, index) in item.messages"
                                :id="`message-${message.id}`"
                                :key="message.id"
                                role="listitem"
                                :aria-label="
                                    messageAccessibleName(
                                        item.author.name,
                                        message.createdAt,
                                        viewerTimeZone,
                                    )
                                "
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
                                <!-- Per-message timestamp: revealed in the avatar
                                 gutter on hover, and hidden from AT since the
                                 row's accessible name already carries the time.
                                 The `<time datetime>` keeps the row machine-
                                 readable regardless of the hover styling. -->
                                <time
                                    :datetime="message.createdAt"
                                    aria-hidden="true"
                                    class="pointer-events-none absolute top-1.5 -left-[52px] font-mono text-[9.5px] text-muted-foreground opacity-0 transition-opacity group-hover/message:opacity-100"
                                    >{{ formatTime(message.createdAt) }}</time
                                >
                                <button
                                    v-if="
                                        message.replyTo &&
                                        !message.isDeleted &&
                                        editingId !== message.id
                                    "
                                    type="button"
                                    data-test="message-quote"
                                    :aria-label="
                                        $t(
                                            'Jump to replied message from :author',
                                            {
                                                author: message.replyTo
                                                    .authorName,
                                            },
                                        )
                                    "
                                    class="mt-0.5 flex max-w-full items-center rounded pr-1 text-left hover:opacity-80"
                                    @click="emit('jump', message.replyTo.id)"
                                >
                                    <MessageQuote
                                        :author-name="
                                            message.replyTo.authorName
                                        "
                                        :body="message.replyTo.body"
                                        :is-deleted="message.replyTo.isDeleted"
                                    />
                                </button>

                                <p
                                    v-if="message.isDeleted"
                                    :data-test="'message-tombstone'"
                                    class="py-0.5 font-serif text-[13.5px] text-muted-foreground italic"
                                >
                                    {{ $t('This message was deleted') }}
                                </p>

                                <div
                                    v-else-if="editingId === message.id"
                                    class="py-0.5"
                                >
                                    <textarea
                                        :ref="setEditField"
                                        v-model="editDraft"
                                        data-test="message-edit-input"
                                        rows="1"
                                        class="w-full resize-none rounded-md border border-input bg-background px-2.5 py-1.5 text-[14.5px] leading-[1.55] text-foreground outline-none focus:border-ring focus:ring-1 focus:ring-ring"
                                        @keydown.enter.exact.prevent="
                                            saveEdit(message)
                                        "
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
                                            {{ $t('Save') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded border border-border px-2 py-1 font-medium text-foreground hover:bg-muted"
                                            @click="cancelEdit"
                                        >
                                            {{ $t('Cancel') }}
                                        </button>
                                        <span>{{
                                            $t('Enter to save · Esc to cancel')
                                        }}</span>
                                    </div>
                                </div>

                                <p
                                    v-else-if="message.body !== ''"
                                    :data-test="'message-body'"
                                    class="py-0.5 text-[14.5px] leading-[1.55] break-words whitespace-pre-wrap text-foreground/90"
                                    :class="
                                        isPending(message) ? 'opacity-60' : ''
                                    "
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
                                            v-else-if="
                                                segment.kind === 'mention'
                                            "
                                            :team-slug="props.teamSlug"
                                            :user-id="segment.id"
                                            :name="segment.name"
                                            @mention="
                                                (member) =>
                                                    emit('mention', member)
                                            "
                                        >
                                            <span
                                                data-test="message-mention"
                                                class="cursor-pointer border-b-[1.5px] border-brass font-medium text-foreground hover:border-brass-border"
                                                >@{{ segment.name }}</span
                                            >
                                        </UserHoverCard>
                                        <img
                                            v-else-if="segment.kind === 'emoji'"
                                            :src="segment.url"
                                            :alt="`:${segment.name}:`"
                                            :title="`:${segment.name}:`"
                                            data-test="message-emoji"
                                            class="custom-emoji inline-block h-[1.35em] w-[1.35em] align-text-bottom"
                                        />
                                        <template v-else>
                                            <HoverCard
                                                v-if="
                                                    previewFor(
                                                        message,
                                                        segment.href,
                                                    )
                                                "
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
                                        class="ml-1 align-baseline text-[11px] text-muted-foreground select-none"
                                        >{{ $t('(edited)') }}</span
                                    >
                                </p>

                                <span
                                    v-if="isQueued(message)"
                                    data-test="message-queued"
                                    class="mt-0.5 inline-flex items-center gap-1 text-[11px] font-semibold text-muted-foreground select-none"
                                >
                                    <Clock class="size-3" />
                                    {{ $t('Queued — will send on reconnect') }}
                                </span>

                                <MessageForward
                                    v-if="
                                        message.forwardedFrom &&
                                        !message.isDeleted &&
                                        editingId !== message.id
                                    "
                                    data-test="forwarded-message"
                                    :author-name="
                                        message.forwardedFrom.authorName
                                    "
                                    :channel-name="
                                        message.forwardedFrom.channelName
                                    "
                                    :body="message.forwardedFrom.body"
                                    :is-deleted="
                                        message.forwardedFrom.isDeleted
                                    "
                                    :mentions="message.forwardedFrom.mentions"
                                />

                                <MessageReactions
                                    v-if="
                                        !message.isDeleted &&
                                        editingId !== message.id
                                    "
                                    :reactions="message.reactions"
                                    :current-user-id="props.currentUserId"
                                    :can-react="canReactTo(message)"
                                    @toggle="
                                        (emoji) => emit('react', message, emoji)
                                    "
                                />

                                <div
                                    v-if="showThreadSummary(message)"
                                    class="mt-1.5 flex flex-wrap items-center gap-2"
                                >
                                    <button
                                        type="button"
                                        data-test="thread-summary"
                                        :aria-label="
                                            message.threadReplyCount === 1
                                                ? $t(
                                                      'View thread, :count reply',
                                                      {
                                                          count: message.threadReplyCount,
                                                      },
                                                  )
                                                : $t(
                                                      'View thread, :count replies',
                                                      {
                                                          count: message.threadReplyCount,
                                                      },
                                                  )
                                        "
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
                                                {{
                                                    getInitials(
                                                        participant.name,
                                                    )
                                                }}
                                            </span>
                                            <span
                                                v-if="
                                                    extraThreadParticipants(
                                                        message,
                                                    ) > 0
                                                "
                                                class="flex size-4 items-center justify-center rounded-full bg-muted text-[8px] font-semibold text-muted-foreground ring-2 ring-card select-none"
                                                aria-hidden="true"
                                            >
                                                +{{
                                                    extraThreadParticipants(
                                                        message,
                                                    )
                                                }}
                                            </span>
                                        </span>
                                        <span
                                            v-if="message.threadUnread"
                                            data-test="thread-unread-dot"
                                            :aria-label="$t('Unread replies')"
                                            class="size-2 shrink-0 rounded-full bg-rose-500"
                                        ></span>
                                        <span
                                            class="text-[12px] font-semibold text-foreground"
                                        >
                                            {{
                                                message.threadReplyCount === 1
                                                    ? $t(':count reply', {
                                                          count: message.threadReplyCount,
                                                      })
                                                    : $t(':count replies', {
                                                          count: message.threadReplyCount,
                                                      })
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
                                        {{ $t('Last reply') }}
                                        {{
                                            formatTime(
                                                message.threadLastReplyAt,
                                            )
                                        }}
                                    </span>
                                </div>

                                <MessageActions
                                    v-if="editingId !== message.id"
                                    :message="message"
                                    :current-user-id="props.currentUserId"
                                    :can-react="props.canReact"
                                    :can-moderate="props.canModerate"
                                    :in-thread="props.inThread"
                                    :pending="isPending(message)"
                                    :viewer-timezone="viewerTimezone"
                                    @react="
                                        (emoji) => emit('react', message, emoji)
                                    "
                                    @reply="emit('reply', message)"
                                    @forward="emit('forward', message)"
                                    @open-thread="
                                        emit('openThread', message.id)
                                    "
                                    @remind="
                                        (remindAt) =>
                                            emit('remind', message, remindAt)
                                    "
                                    @remind-custom="
                                        emit('remindCustom', message)
                                    "
                                    @edit="startEdit(message)"
                                    @delete="requestDelete(message)"
                                />
                            </div>
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
                {{ $t('Seen by') }}
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
                    <DialogTitle>{{ $t('Delete message') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                "Are you sure you want to delete this message? This can't be undone.",
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">
                            {{ $t('Cancel') }}
                        </Button>
                    </DialogClose>

                    <Button
                        data-test="delete-message-confirm"
                        variant="destructive"
                        @click="confirmDelete"
                    >
                        {{ $t('Delete') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
