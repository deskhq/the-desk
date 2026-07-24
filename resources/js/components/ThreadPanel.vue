<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import { ChevronLeft, Ellipsis, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import MessageComposer from '@/components/MessageComposer.vue';
import MessageList from '@/components/MessageList.vue';
import ScrollableMessageList from '@/components/ScrollableMessageList.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { useIsMobile } from '@/composables/useIsMobile';
import { useScrollPin } from '@/composables/useScrollPin';
import type { RenderedPresence } from '@/lib/presence';
import type { Mention, Message } from '@/types';

const props = defineProps<{
    /**
     * The open thread's root id, used to key the reply scroll so switching
     * threads mounts a fresh, bottom-anchored list.
     */
    rootId: string;
    teamSlug: string;
    channelName: string;
    /**
     * The root followed by its replies (oldest first), merged and deduped by the
     * parent's thread stream; empty while the thread is still loading.
     */
    messages: Message[];
    pendingUuids?: string[];
    members: Mention[];
    // Whether the channel has a bot member, forwarded to the reply composer's
    // mention menu footnote.
    hasBots?: boolean;
    currentUserId: string;
    canModerate?: boolean;
    canReact?: boolean;
    canPin?: boolean;
    /** How each author reads on the team presence roster, passed straight down. */
    presenceFor?: (userId: string) => RenderedPresence;
    /** Whether each author is in do-not-disturb, threaded through to the list. */
    isDndFor?: (userId: string) => boolean;
    loading?: boolean;
    readOnly?: boolean;
}>();

const emit = defineEmits<{
    close: [];
    send: [body: string, mentions: Mention[], sendToChannel?: boolean];
    edit: [message: Message, body: string];
    delete: [message: Message];
    forward: [message: Message];
    react: [message: Message, emoji: string];
    vote: [message: Message, optionId: string];
    closePoll: [message: Message];
    pin: [message: Message];
    unpin: [message: Message];
    remind: [message: Message, remindAt: string];
    remindCustom: [message: Message];
    typing: [];
    jump: [messageId: string];
}>();

/** The root is the thread's only top-level message; everything else is a reply. */
const root = computed(() =>
    props.messages.find((message) => message.threadRootId === null),
);
const hasRoot = computed(() => root.value !== undefined);
/**
 * The header shows the thread's total reply count (denormalized on the root),
 * not just the replies currently paged into view.
 */
const replyCount = computed(() => root.value?.threadReplyCount ?? 0);
const showSkeleton = computed(() => props.loading || !hasRoot.value);

/**
 * Below the breakpoint the panel is a full-screen push (design `m3`): the reply
 * count moves from the header into a rule under the root, and the composer's
 * placeholder names the thread it replies into.
 */
const isMobile = useIsMobile();

/**
 * Shared scroll/pin bookkeeping, identical to the main timeline's: the
 * pinned-to-newest flag, the "N new replies" count while scrolled up, and the
 * smooth jump back to the bottom.
 */
const scrollContainer = ref<HTMLElement | null>(null);
/**
 * `ScrollableMessageList` owns the scroll element; this points our ref at it so
 * `useScrollPin` binds to the very same node.
 */
const setScrollContainer = (el: HTMLElement | null): void => {
    scrollContainer.value = el;
};
/**
 * The windowed `MessageList` exposes `scrollToLatest`, so the jump-to-newest
 * lands on the real bottom: a native `scrollTo(scrollHeight)` settles short on
 * the virtualizer's estimated spacer (#347). Inertia's manual `<InfiniteScroll>`
 * exposes the older-page fetch. Both are read through template refs.
 */
const messageListRef = ref<{
    scrollToIndex: (
        index: number,
        align?: 'auto' | 'start' | 'center' | 'end',
    ) => void;
    scrollToLatest: (behavior?: ScrollBehavior) => void;
} | null>(null);

const infiniteScrollRef = ref<{
    fetchNext: () => void;
    hasNext: () => boolean;
} | null>(null);

/**
 * True while an older reply page is being fetched, gating the virtualizer's
 * top-load trigger so a burst of range updates during a fast scroll can't stack
 * duplicate requests. Cleared once the merged list grows (older replies landed).
 */
const loadingOlder = ref(false);

/**
 * In reverse mode the server returns replies newest-first, so "load older" maps
 * to the paginator's *next* page.
 */
const hasOlder = (): boolean => infiniteScrollRef.value?.hasNext() ?? false;

const isLoadingOlder = (): boolean => loadingOlder.value;

/**
 * Fetch the next older reply page through Inertia's merge engine; the virtualizer
 * decides *when* (the reader nears the top of loaded history) via `@load-older`.
 */
function loadOlderReplies(): void {
    if (loadingOlder.value || !hasOlder()) {
        return;
    }

    loadingOlder.value = true;
    infiniteScrollRef.value?.fetchNext();
}

// The merged reply list grows once an older page lands (or a reply is appended);
// either way the in-flight fetch is done, so release the gate.
watch(
    () => props.messages.length,
    () => {
        loadingOlder.value = false;
    },
);

const {
    pinnedToBottom,
    newMessageCount,
    isNearBottom,
    scrollToBottom,
    notifyAppended,
    onScroll,
} = useScrollPin(scrollContainer, {
    // The reply list is windowed, so a native `scrollTo(scrollHeight)` lands short
    // of the newest reply; drive the jump through the virtualizer, which re-targets
    // the true bottom as off-screen rows measure (#347).
    scrollToLatest: (smooth) =>
        messageListRef.value?.scrollToLatest(smooth ? 'smooth' : 'auto'),
});

/**
 * The thread reply composer, so a hover card on a thread message can drop a
 * mention into it rather than the main channel composer.
 */
const threadComposer = ref<InstanceType<typeof MessageComposer> | null>(null);

function mentionInThread(member: { id: string; name: string }): void {
    threadComposer.value?.insertMention(member);
}

/**
 * The reply the thread composer is editing in place (via the ↑ shortcut), or
 * null. Highlights the target row in the thread while editing.
 */
const editingMessageId = ref<string | null>(null);

/**
 * The id of the newest reply currently shown, so a reply appended at the bottom
 * (which should follow the reader or raise the count) can be told apart from
 * older replies paging in at the top, which must leave the viewport anchored.
 */
const newestMessageId = ref<string | null>(null);

// Keep the panel pinned to the newest reply as the conversation grows: the
// initial load lands at the bottom, a later reply appended at the bottom follows
// the reader down (or raises the "new replies" count while they're scrolled up),
// and older replies paging in above are left in place. `isNearBottom()` is read
// pre-flush, so it reflects the position before the new row grows the panel.
watch(
    () => props.messages,
    (messages, previous) => {
        const newestId = messages[messages.length - 1]?.id ?? null;
        const previousLength = previous?.length ?? 0;

        if (messages.length > previousLength) {
            const wasNearBottom = isNearBottom();

            if (previousLength === 0) {
                nextTick(() => scrollToBottom());
            } else if (newestId !== newestMessageId.value) {
                notifyAppended(wasNearBottom);
            }
        }

        newestMessageId.value = newestId;
    },
);
</script>

<template>
    <aside
        data-test="thread-panel"
        class="z-30 flex w-full min-w-0 shrink-0 flex-col overflow-hidden max-md:absolute max-md:inset-0 max-md:bg-card md:rounded-[14px] md:border md:border-border md:bg-sidebar md:shadow-sm md:max-lg:absolute md:max-lg:inset-0 md:max-lg:w-auto lg:relative lg:m-3.5 lg:w-96"
    >
        <header
            class="flex shrink-0 items-center gap-2.5 border-b border-border px-4 pt-4 pb-3 md:items-start md:gap-2 md:px-4.5"
        >
            <!-- The push's way back to the channel, in the 36px tap surface the
                 design draws. Same close path as the desktop X — the channel
                 beneath kept its scroll position while covered. -->
            <Button
                variant="ghost"
                size="icon"
                type="button"
                data-test="thread-back"
                :aria-label="$t('Back to channel')"
                class="-my-1.5 -ml-2 size-9 shrink-0 rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground md:hidden"
                @click="emit('close')"
            >
                <ChevronLeft class="size-4.5" />
            </Button>
            <div class="min-w-0 flex-1">
                <h2
                    class="font-serif text-[20px] leading-[1.1] font-semibold text-foreground md:text-[19px]"
                >
                    {{ $t('Thread') }}
                </h2>
                <p
                    data-test="thread-reply-count"
                    class="mt-0.5 text-[11.5px] text-muted-foreground max-md:truncate"
                >
                    <!-- Below the breakpoint the count lives in the rule under
                         the root, so the sub-line is the channel alone with the
                         masthead's brass italic hash. -->
                    <span v-if="replyCount > 0" class="hidden md:inline"
                        >{{
                            replyCount === 1
                                ? $t(':count reply', { count: replyCount })
                                : $t(':count replies', { count: replyCount })
                        }}
                        ·
                    </span>
                    <span
                        class="font-serif text-brass italic md:hidden"
                        aria-hidden="true"
                        >#</span
                    ><span class="hidden md:inline">#</span
                    >{{ props.channelName }}
                </p>
            </div>
            <Button
                variant="outline"
                size="icon-sm"
                type="button"
                data-test="thread-close"
                :aria-label="$t('Close thread')"
                class="shrink-0 rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground max-md:hidden"
                @click="emit('close')"
            >
                <X />
            </Button>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        type="button"
                        data-test="thread-options"
                        :aria-label="$t('Thread options')"
                        class="-my-1.5 -mr-2 size-9 shrink-0 rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground md:hidden"
                    >
                        <Ellipsis class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-48">
                    <DropdownMenuItem
                        data-test="thread-options-close"
                        @select="emit('close')"
                    >
                        <X class="size-4" />
                        {{ $t('Close thread') }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </header>

        <ScrollableMessageList
            variant="thread"
            :register-container="setScrollContainer"
            :pinned-to-bottom="pinnedToBottom"
            :new-message-count="newMessageCount"
            @scroll="onScroll"
            @jump="scrollToBottom(true)"
        >
            <!-- Older replies page in as the virtualizer nears the top of loaded
                 history; a floating loader sits at the top while that fetch is in
                 flight, mirroring the main timeline. Kept ahead of the skeleton /
                 list branch so their `v-if`/`v-else` pair stays adjacent. -->
            <Transition
                enter-active-class="transition duration-150 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-100 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="loadingOlder"
                    data-test="thread-loading-older"
                    class="pointer-events-none absolute inset-x-0 top-2 z-10 flex justify-center"
                >
                    <span
                        class="inline-flex items-center gap-2 rounded-full bg-card px-3 py-1 text-[12px] text-muted-foreground shadow-sm ring-1 ring-border"
                    >
                        <span
                            aria-hidden="true"
                            class="size-3 animate-spin rounded-full border-2 border-border border-t-foreground"
                        />
                        {{ $t('Loading earlier replies…') }}
                    </span>
                </div>
            </Transition>

            <div v-if="showSkeleton" class="space-y-4 p-5">
                <div class="flex gap-3">
                    <Skeleton class="size-9 rounded-[10px]" />
                    <div class="flex-1 space-y-2">
                        <Skeleton class="h-3 w-24" />
                        <Skeleton class="h-3 w-3/4" />
                    </div>
                </div>
                <div class="flex gap-3">
                    <Skeleton class="size-9 rounded-[10px]" />
                    <div class="flex-1 space-y-2">
                        <Skeleton class="h-3 w-20" />
                        <Skeleton class="h-3 w-1/2" />
                    </div>
                </div>
            </div>

            <!-- Older replies page in on scroll-up; keyed by root so switching
                 threads mounts a fresh, bottom-anchored list. `manual` hands the
                 load trigger to the virtualizer's range (only visible reply rows
                 mount, so the automatic sentinel can't be relied on), and
                 `preserve-url` keeps the cursor out of the URL. -->
            <InfiniteScroll
                v-else
                ref="infiniteScrollRef"
                :key="props.rootId"
                data="threadReplies"
                reverse
                manual
                preserve-url
            >
                <MessageList
                    ref="messageListRef"
                    virtualize
                    :scroll-element="scrollContainer"
                    :has-older="hasOlder"
                    :is-loading-older="isLoadingOlder"
                    :messages="props.messages"
                    :team-slug="props.teamSlug"
                    :pending-uuids="props.pendingUuids"
                    :current-user-id="props.currentUserId"
                    :can-moderate="props.canModerate"
                    :can-react="props.canReact"
                    :can-pin="props.canPin"
                    :presence-for="props.presenceFor"
                    :is-dnd-for="props.isDndFor"
                    :editing-message-id="editingMessageId"
                    :reply-divider-count="isMobile ? replyCount : 0"
                    in-thread
                    @load-older="loadOlderReplies"
                    @edit="(message, body) => emit('edit', message, body)"
                    @delete="(message) => emit('delete', message)"
                    @forward="(message) => emit('forward', message)"
                    @react="(message, emoji) => emit('react', message, emoji)"
                    @vote="
                        (message, optionId) => emit('vote', message, optionId)
                    "
                    @close-poll="(message) => emit('closePoll', message)"
                    @pin="(message) => emit('pin', message)"
                    @unpin="(message) => emit('unpin', message)"
                    @remind="
                        (message, remindAt) => emit('remind', message, remindAt)
                    "
                    @remind-custom="(message) => emit('remindCustom', message)"
                    @jump="(id) => emit('jump', id)"
                    @mention="mentionInThread"
                />
            </InfiniteScroll>
        </ScrollableMessageList>

        <MessageComposer
            :has-bots="props.hasBots"
            v-if="hasRoot && !props.readOnly"
            ref="threadComposer"
            :key="root?.id"
            :channel-name="props.channelName"
            :members="props.members"
            :placeholder="isMobile ? $t('Reply in thread') : $t('Reply…')"
            :messages="props.messages"
            :current-user-id="props.currentUserId"
            :pending-uuids="props.pendingUuids"
            allow-send-to-channel
            autofocus
            @send="
                (body, mentions, sendToChannel) =>
                    emit('send', body, mentions, sendToChannel)
            "
            @typing="emit('typing')"
            @edit="(message, body) => emit('edit', message, body)"
            @editing-change="editingMessageId = $event"
        />
    </aside>
</template>
