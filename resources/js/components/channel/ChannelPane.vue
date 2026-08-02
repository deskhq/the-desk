<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ChannelDropOverlay from '@/components/channel/ChannelDropOverlay.vue';
import LoadingOlderPill from '@/components/channel/LoadingOlderPill.vue';
import StickyDayChip from '@/components/channel/StickyDayChip.vue';
import UnreadJumpPill from '@/components/channel/UnreadJumpPill.vue';
import ChannelEmptyState from '@/components/ChannelEmptyState.vue';
import MessageList from '@/components/MessageList.vue';
import ScrollableMessageList from '@/components/ScrollableMessageList.vue';
import { useChannelHistory } from '@/composables/useChannelHistory';
import {
    provideMessageSubtree,
    useMessageActionsContext,
} from '@/composables/useMessageActionsContext';
import { useMessageHighlight } from '@/composables/useMessageHighlight';
import { usePaneFileDrop } from '@/composables/usePaneFileDrop';
import { useStickyDayLabel } from '@/composables/useStickyDayLabel';
import { useUnreadDivider } from '@/composables/useUnreadDivider';
import { useUnreadJump } from '@/composables/useUnreadJump';
import type { TimelineRange } from '@/composables/useUnreadJump';
import type { RenderedPresence } from '@/lib/presence';
import { buildTimelineItems } from '@/lib/timeline';
import type { Channel, ChannelReader, Message } from '@/types';

/**
 * The conversation pane: the whole-pane file drop zone, the windowed timeline
 * with its floating affordances, and the bottom slot the page fills with the
 * composer (or the archived notice / join bar that stand in for it).
 *
 * The page keeps its scroll pin, since the realtime and action layers reconcile
 * against it; this owns everything derived from the virtualizer's window.
 */
const props = defineProps<{
    team: { name: string; slug: string };
    channel: Channel;
    /** The live-merged timeline: server page + optimistic sends + echoes. */
    messages: Message[];
    /**
     * The server page alone, oldest-first. The unread boundary reads it rather
     * than the merged list so it is immune to the order per-channel state resets
     * on a channel switch.
     */
    serverMessages: Message[];
    /** Whether this is the viewer's own self-DM, so the empty state reads "You". */
    isSelfDm: boolean;
    pendingUuids: string[];
    queuedUuids: string[];
    /** Whether a file drop would be staged (a member of a live channel). */
    canDropFiles: boolean;
    presenceFor: (userId: string) => RenderedPresence;
    isDndFor: (userId: string) => boolean;
    readers: ChannelReader[];
    activeThreadRootId: string | null;
    editingMessageId: string | null;
    /** The viewer's read pointer at load time, placing the unread divider. */
    lastReadMessageId: string | null;
    /** Function ref handing the scroll element back to the page's scroll pin. */
    registerContainer: (el: HTMLElement | null) => void;
    pinnedToBottom: boolean;
    newMessageCount: number;
    /** The page's scroll pin, so a jump to present goes through one place. */
    scrollToBottom: (smooth?: boolean) => void;
}>();

const emit = defineEmits<{
    /** The history scrolled; the page forwards it to its scroll pin. */
    scroll: [];
    /** Files were dropped over the pane, for the composer's tray. */
    files: [files: File[]];
    /** The empty state's "Post your first message" card was used. */
    focusComposer: [];
    /**
     * A hover card in the main timeline offered a mention; the page drops it
     * into the channel composer, which it owns through the pane's bottom slot.
     */
    mention: [member: { id: string; name: string }];
}>();

/**
 * The main timeline's own scope: not a thread, and a mention lands in the
 * channel composer. Every row below reads this instead of being told.
 */
provideMessageSubtree({
    inThread: false,
    mention: (member) => emit('mention', member),
});

const scope = useMessageActionsContext();

/**
 * The scroll element the virtualizer and the unread divider bind to. It reaches
 * the page's own scroll pin through `registerContainer`, so all three watch the
 * very same node.
 */
const scrollElement = ref<HTMLElement | null>(null);

function setScrollContainer(el: HTMLElement | null): void {
    scrollElement.value = el;
    props.registerContainer(el);
}

/**
 * Whether the timeline has conversation scrolled above its top edge. Below the
 * breakpoint the masthead sits over the timeline rather than beside it, so it
 * takes a hairline shadow exactly when there is something passing under it.
 */
const scrolled = ref(false);

function onScrolled(): void {
    scrolled.value = (scrollElement.value?.scrollTop ?? 0) > 0;
    emit('scroll');
}

watch(
    () => props.channel.id,
    () => {
        scrolled.value = false;
    },
);

/**
 * The windowed timeline exposes `scrollToIndex` so off-screen jump targets can be
 * brought into view, and `scrollToLatest` so the page's scroll pin can drive a
 * jump the virtualizer re-targets as rows measure (#347).
 */
const messageListRef = ref<{
    scrollToIndex: (
        index: number,
        align?: 'auto' | 'start' | 'center' | 'end',
    ) => void;
    scrollToLatest: (behavior?: ScrollBehavior) => void;
} | null>(null);

/**
 * The virtualizer's visible render-item window, from which the position-dependent
 * affordances are derived without a DOM `IntersectionObserver`.
 */
const timelineRange = ref<TimelineRange | null>(null);

const scrollToIndex = (index: number, align: 'start' | 'center'): void =>
    messageListRef.value?.scrollToIndex(index, align);

const hasMessages = computed(() => props.messages.length > 0);

const {
    infiniteScrollRef,
    loadingOlder,
    hasOlder,
    isLoadingOlder,
    loadOlderMessages,
} = useChannelHistory({
    channelId: () => props.channel.id,
    loadedCount: () => props.serverMessages.length,
});

/**
 * The "New messages" divider's lifecycle — freeze its position at open, refreeze
 * on each channel switch, and hide the jump pill once it scrolls into view —
 * lives in this composable.
 */
const { unreadDividerId } = useUnreadDivider({
    channelId: () => props.channel.id,
    scrollContainer: scrollElement,
    messages: () => props.serverMessages,
    lastReadMessageId: () => props.lastReadMessageId,
    currentUserId: () => scope.currentUserId,
});

/**
 * The same grouped render list the virtualized `MessageList` builds, recomputed
 * here so index-based affordances (jump-to-message, the unread boundary) can map
 * a message or divider to the render-item index the virtualizer scrolls to.
 */
const timelineItems = computed(() =>
    buildTimelineItems(props.messages, unreadDividerId.value ?? null),
);

const { highlightedMessageId, jumpToMessage } = useMessageHighlight({
    timelineItems: () => timelineItems.value,
    scrollToIndex,
});

const { showJumpToUnread, scrollToUnread, jumpToPresent } = useUnreadJump({
    channelId: () => props.channel.id,
    timelineItems: () => timelineItems.value,
    timelineRange,
    scrollToIndex,
    scrollToBottom: (smooth) => props.scrollToBottom(smooth),
});

const stickyDayLabel = useStickyDayLabel({
    timelineItems: () => timelineItems.value,
    timelineRange,
});

const {
    isDraggingFiles,
    canDropAttachments,
    onPaneDragEnter,
    onPaneDragOver,
    onPaneDragLeave,
    onPaneDrop,
} = usePaneFileDrop({
    canDrop: () => props.canDropFiles,
    onFiles: (files) => emit('files', files),
});

defineExpose({
    /** Whether conversation has scrolled under the masthead, which takes a shadow. */
    scrolled,
    jumpToMessage,
    scrollToLatest: (behavior?: ScrollBehavior) =>
        messageListRef.value?.scrollToLatest(behavior),
});
</script>

<template>
    <!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions -- the pane is a file drop zone; keyboard users attach via the composer's Add-attachment button -->
    <div
        class="relative flex min-h-0 flex-1 flex-col"
        @dragenter="onPaneDragEnter"
        @dragover="onPaneDragOver"
        @dragleave="onPaneDragLeave"
        @drop="onPaneDrop"
    >
        <ChannelDropOverlay
            v-if="isDraggingFiles && canDropAttachments()"
            :channel-name="props.channel.name"
        />

        <div class="relative flex min-h-0 flex-1 flex-col">
            <UnreadJumpPill :show="showJumpToUnread" @jump="scrollToUnread" />

            <StickyDayChip
                :label="
                    !props.pinnedToBottom && !showJumpToUnread
                        ? stickyDayLabel
                        : null
                "
            />

            <!-- The message history is a focusable, labelled region so keyboard
                 users can Tab to it and arrow-scroll (which remounts virtualized
                 rows). `ScrollableMessageList` renders the region + the
                 jump-to-latest pill; the timeline itself can't be a `role="log"`
                 since its rows unmount, so `role="region"` names it instead. -->
            <ScrollableMessageList
                variant="channel"
                :region-label="$t('Message history')"
                :register-container="setScrollContainer"
                :pinned-to-bottom="props.pinnedToBottom"
                :new-message-count="props.newMessageCount"
                @scroll="onScrolled"
                @jump="jumpToPresent"
            >
                <LoadingOlderPill :show="loadingOlder" />

                <InfiniteScroll
                    v-if="hasMessages"
                    ref="infiniteScrollRef"
                    data="messages"
                    reverse
                    manual
                    preserve-url
                >
                    <MessageList
                        ref="messageListRef"
                        virtualize
                        :scroll-element="scrollElement"
                        :has-older="hasOlder"
                        :is-loading-older="isLoadingOlder"
                        :messages="props.messages"
                        :team-slug="props.team.slug"
                        :pending-uuids="props.pendingUuids"
                        :queued-uuids="props.queuedUuids"
                        :is-direct="props.channel.isDirect"
                        :presence-for="props.presenceFor"
                        :is-dnd-for="props.isDndFor"
                        :readers="props.readers"
                        :highlight-message-id="highlightedMessageId"
                        :unread-divider-id="unreadDividerId"
                        :active-thread-root-id="props.activeThreadRootId"
                        :editing-message-id="props.editingMessageId"
                        @load-older="loadOlderMessages"
                        @range-change="timelineRange = $event"
                    />
                </InfiniteScroll>

                <ChannelEmptyState
                    v-else
                    :channel="props.channel"
                    :is-self-dm="props.isSelfDm"
                    :team-name="props.team.name"
                    :team-slug="props.team.slug"
                    @focus-composer="emit('focusComposer')"
                />
            </ScrollableMessageList>
        </div>

        <!-- The bottom slot: the composer, or what stands in for it (the
             archived notice, the join bar). It sits inside the drop zone so a
             file dropped over the composer stages just as it does over the
             history. -->
        <slot />
    </div>
</template>
