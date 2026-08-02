<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import ArchivedNotice from '@/components/channel/ArchivedNotice.vue';
import ChannelAnnouncers from '@/components/channel/ChannelAnnouncers.vue';
import ChannelComposerDock from '@/components/channel/ChannelComposerDock.vue';
import ChannelDialogs from '@/components/channel/ChannelDialogs.vue';
import ChannelHeader from '@/components/channel/ChannelHeader.vue';
import ChannelPane from '@/components/channel/ChannelPane.vue';
import JoinChannelBar from '@/components/JoinChannelBar.vue';
import ThreadPanel from '@/components/ThreadPanel.vue';
import { useChannelDraft } from '@/composables/useChannelDraft';
import { useChannelIdentity } from '@/composables/useChannelIdentity';
import { useChannelReaders } from '@/composables/useChannelReaders';
import { useChannelRealtime } from '@/composables/useChannelRealtime';
import { useChannelTimeline } from '@/composables/useChannelTimeline';
import { useChannelTyping } from '@/composables/useChannelTyping';
import { useComposerTarget } from '@/composables/useComposerTarget';
import { useMessageActions } from '@/composables/useMessageActions';
import { provideMessageActions } from '@/composables/useMessageActionsContext';
import { useOfflineOutbox } from '@/composables/useOfflineOutbox';
import { useRailBottomInset } from '@/composables/useRailInset';
import { useReadOnFocus } from '@/composables/useReadOnFocus';
import { useReadPointer } from '@/composables/useReadPointer';
import { useScrollPin } from '@/composables/useScrollPin';
import { useTeamPresence } from '@/composables/useTeamPresence';
import { useThreadPanel } from '@/composables/useThreadPanel';
import { useTimezone } from '@/composables/useTimezone';
import type {
    Channel,
    ChannelReader,
    Message,
    MessagePage,
    NotificationLevelOption,
    RosterMember,
    ScheduledMessage,
    Thread,
} from '@/types';

const props = defineProps<{
    team: { id: string; name: string; slug: string };
    channel: Channel;
    messages: MessagePage;
    members: RosterMember[];
    canArchive: boolean;
    /** Whether the viewer may delete it (a team Admin+, not #general or a DM). */
    canDelete: boolean;
    canManagePreferences: boolean;
    /**
     * Whether the viewer may reword the channel's topic and description (any
     * member of a live standard channel); drives the details modal's edit mode.
     */
    canEditChannel: boolean;
    /** Whether the viewer may also rename it (the creator or a team Admin+). */
    canRenameChannel: boolean;
    /**
     * Whether the viewer may leave the channel (a member of a standard channel
     * that isn't #general); drives the "Leave channel" menu item and modal.
     */
    canLeave: boolean;
    /**
     * Whether the viewer may react (member of a non-archived channel); read-only
     * reaction pills still render for a non-member browsing a public channel.
     */
    canReact: boolean;
    /**
     * Whether the viewer already belongs to the channel. A non-member viewing a
     * public channel by URL sees a "Join channel" bar in place of the composer.
     */
    isMember: boolean;
    /** The channel's member count, surfaced in the join bar for a non-member. */
    memberCount: number;
    /**
     * The channel's pinned-message count, driving the masthead badge on first
     * load; kept live from the MessagePinned broadcast thereafter.
     */
    pinCount: number;
    /**
     * The channel's pinned messages, most-recently-pinned first, feeding the pins
     * popover. Refreshed via a partial reload when the panel opens or a pin
     * changes; each row is a full Message (its `pin` carries the attribution).
     */
    pins: Message[];
    notificationLevels: NotificationLevelOption[];
    jumpToMessageId?: string | null;
    /**
     * The viewer's read pointer at load time, used to place the "New messages"
     * divider; null when the channel has never been read.
     */
    lastReadMessageId?: string | null;
    /**
     * Read positions of the channel's other members who share read receipts,
     * seeding the "Seen by" affordance; later advances arrive via MessageRead.
     */
    channelReaders: ChannelReader[];
    /** The open thread's root, loaded on demand keyed by `?thread=`. */
    thread?: Thread | null;
    /**
     * The open thread's replies, a reverse-infinite-scroll page that grows as
     * older replies load. Empty when no thread is open.
     */
    threadReplies: MessagePage;
    /**
     * The viewer's own pending scheduled messages for this channel, soonest
     * first, feeding the composer's "Scheduled" affordance.
     */
    scheduledMessages: ScheduledMessage[];
}>();

const {
    currentUser,
    isSelfDm,
    mastheadTitle,
    pageTitle,
    canAddPeople,
    composerPlaceholder,
    canModerate,
    mentionableMembers,
    channelHasBots,
} = useChannelIdentity({
    channel: () => props.channel,
    members: () => props.members,
    isMember: () => props.isMember,
});

const typing = useChannelTyping({
    teamSlug: () => props.team.slug,
    channelSlug: () => props.channel.slug,
});

const typingNames = typing.typingNames;

/**
 * Live presence for the team, driving the dots on message avatars, the masthead
 * and the facepile. Follows the team across channel switches.
 */
const { presenceFor, isDndFor } = useTeamPresence(() => props.team.id);

const { readers, seedReaders, channelReadersList } = useChannelReaders({
    channelReaders: () => props.channelReaders,
});

/** The page's live regions, which also announce a send that failed. */
const announcers = ref<InstanceType<typeof ChannelAnnouncers> | null>(null);

/** The channel header, which owns the viewer's own preferences and the pins. */
const header = ref<InstanceType<typeof ChannelHeader> | null>(null);

/** Every dialog the page can raise, opened through its exposed handlers. */
const dialogs = ref<InstanceType<typeof ChannelDialogs> | null>(null);

/**
 * The composer dock, so a profile hover card in the main timeline can drop a
 * mention straight into the composer and a pane drop can stage its files.
 */
const dock = ref<InstanceType<typeof ChannelComposerDock> | null>(null);

/**
 * The conversation pane, through which the page jumps to a message (from the
 * pins popover, a thread, or a search deep link) and returns to the present.
 */
const pane = ref<InstanceType<typeof ChannelPane> | null>(null);

const scrollContainer = ref<HTMLElement | null>(null);
/**
 * The pane owns the scroll element; this points our ref at it so `useScrollPin`
 * binds to the very same node the timeline and its affordances read.
 */
const setScrollContainer = (el: HTMLElement | null): void => {
    scrollContainer.value = el;
};

/**
 * Shared scroll/pin bookkeeping: the pinned-to-newest flag, the "N new messages"
 * count while scrolled up, and the smooth jump back to the bottom. The thread
 * panel runs its own instance of the same composable.
 */
const {
    pinnedToBottom,
    newMessageCount,
    isNearBottom,
    scrollToBottom,
    notifyAppended,
    onScroll,
    reset: resetScrollPin,
} = useScrollPin(scrollContainer, {
    // The timeline is windowed, so a native `scrollTo(scrollHeight)` lands short
    // of the present; drive the jump through the virtualizer, which re-targets
    // the true bottom as off-screen rows measure (#347).
    scrollToLatest: (smooth) =>
        pane.value?.scrollToLatest(smooth ? 'smooth' : 'auto'),
});

const { serverMessages, mainStream, displayMessages, pendingUuids } =
    useChannelTimeline({ messages: () => props.messages });

/**
 * The thread panel's whole open → load → reset → mark-read lifecycle, with its own
 * merge stream over the root plus paginated replies. It resets itself on a channel
 * switch, so this page no longer juggles two stream lifecycles inline;
 * `sendThreadReply` stays in `useMessageActions`, sharing this panel's stream.
 */
const {
    activeThreadRootId,
    threadLoading,
    threadStream,
    threadMessages,
    threadPendingUuids,
    openThread,
    closeThread,
    markThreadRead,
    adoptDeepLinkedThread,
} = useThreadPanel({
    teamSlug: () => props.team.slug,
    channelSlug: () => props.channel.slug,
    channelId: () => props.channel.id,
    mainStream,
    thread: () => props.thread,
    threadReplies: () => props.threadReplies,
});

/**
 * Bring a message into view in the timeline and mark it for a beat. A nullish
 * id is the ordinary "no jump asked for" case (a plain channel visit), so it is
 * a no-op rather than the caller's business to guard.
 */
function jumpToMessage(id: string | null | undefined): void {
    if (id) {
        pane.value?.jumpToMessage(id);
    }
}

const { markRead } = useReadPointer({
    teamSlug: () => props.team.slug,
    channelSlug: () => props.channel.slug,
});

useReadOnFocus(markRead, markThreadRead);

// The active channel's Echo subscribe/route/teardown lives in this composable: it
// moves the subscription as the open channel changes and routes each broadcast
// into the two streams. `Show.vue` only supplies the state it reconciles against.
useChannelRealtime({
    channelId: () => props.channel.id,
    currentUserId: () => currentUser.value.id,
    mainStream,
    threadStream,
    activeThreadRootId,
    displayMessages: () => displayMessages.value,
    alertPreference: () => header.value?.alertPreference ?? null,
    readers,
    isNearBottom,
    notifyAppended,
    typing,
    markRead,
    markThreadRead,
    updatePinCount: (count: number) => header.value?.setPinCount(count),
});

onMounted(() => {
    seedReaders();
    markRead();

    if (props.jumpToMessageId) {
        jumpToMessage(props.jumpToMessageId);
    } else {
        // Land on the newest message. The windowed timeline sizes from height
        // estimates first, so defer past the initial measure before pinning.
        nextTick(() => scrollToBottom());
    }

    // Reopen a deep-linked thread resolved from the `?thread=` param on load.
    adoptDeepLinkedThread();
});

// A jump to another result in the same already-open channel reuses this
// component, so the channel-id watch won't fire; react to the target changing.
watch(() => props.jumpToMessageId, jumpToMessage);

// Inertia may reuse this page component when navigating between channels; reset
// the message-orchestration state this page owns when the channel changes. The
// extracted composables (realtime, draft, preferences, unread divider, thread
// panel) each move or refreeze their own state on the same change via their own
// watchers.
watch(
    () => props.channel.id,
    () => {
        mainStream.reset();
        resetScrollPin();
        replyTarget.value = null;
        typing.reset();
        header.value?.closePins();
        dialogs.value?.closeAddPeople();
        seedReaders();
        markRead();
    },
);

const { replyTarget, composerEditingId, startReply, cancelReply } =
    useComposerTarget();

/**
 * The composer claims the bottom of the conversation pane, so the bottom-right
 * rail sits above it rather than over its send button and scheduled chip.
 */
useRailBottomInset(computed(() => dock.value?.composerElement ?? null));

/**
 * The member's unsent composer text is persisted per channel so it survives
 * navigation, reloads and other devices. This composable owns the debounce, the
 * channel-switch flush, and the flush-on-unmount; a send clears the draft
 * server-side, so only manual edits flow through `onDraftChange`.
 */
const {
    onDraftChange,
    cancel: cancelDraft,
    clear: clearDraft,
} = useChannelDraft({
    channelId: () => props.channel.id,
    teamSlug: () => props.team.slug,
    channelSlug: () => props.channel.slug,
});

/**
 * The realtime connection cue (reconnecting / back-online pill) and the offline
 * outbox: sends made while the socket is down queue here and flush on recovery.
 */
const { connection, outbox, queuedUuids, discardQueue, flushOnReconnect } =
    useOfflineOutbox({
        channelId: () => props.channel.id,
        currentUser: () => currentUser.value,
        mainStream,
    });

/**
 * The channel's optimistic-mutation engine: send/edit/delete/react/forward,
 * thread replies, scheduling, and reminders all follow the same optimistic-apply
 * → router-call → rollback-on-error shape, concentrated behind one seam.
 */
const actions = useMessageActions({
    teamSlug: () => props.team.slug,
    channel: () => props.channel,
    currentUser: () => currentUser.value,
    isOnline: () => connection.isOnline.value,
    onSendFailure: (message) => announcers.value?.announce(message),
    outbox,
    mainStream,
    threadStream,
    activeThreadRootId,
    replyTarget,
    isNearBottom,
    scrollToBottom,
    cancelDraft,
    clearDraft,
    cancelReply,
});

flushOnReconnect(actions.flushOutbox);

/**
 * The viewer's stored timezone, driving the schedule picker's presets and the
 * list's "sends at" labels so a scheduled time always reads in their own zone.
 */
const { timezone } = useTimezone();

/**
 * Publish what a message row needs, once, for the whole page (ADR-0009): who
 * the viewer is, what this channel lets them do, and every action they can ask
 * for. Nothing between here and a row re-declares any of it — the pane, the
 * thread panel, the timeline and the row itself read it where they use it.
 *
 * Some actions are writes the engine above owns; the rest raise a dialog or
 * move the reader. Both kinds look the same from a row, which is the point.
 */
provideMessageActions(
    reactive({
        currentUserId: computed(() => currentUser.value.id),
        canReact: computed(() => props.canReact),
        // Pinning is a shared toggle gated on the same membership as reacting.
        canPin: computed(() => props.canReact),
        canModerate,
        viewerTimeZone: timezone,
        actions: {
            react: actions.reactToMessage,
            vote: actions.voteOnPoll,
            closePoll: actions.closePoll,
            pin: actions.pinMessage,
            unpin: actions.unpinMessage,
            remind: (message: Message, remindAt: string) =>
                dialogs.value?.remindWith(message, remindAt),
            remindCustom: (message: Message) =>
                dialogs.value?.openCustomReminder(message),
            forward: (message: Message) => dialogs.value?.openForward(message),
            jump: jumpToMessage,
            edit: actions.editMessage,
            delete: actions.deleteMessage,
            reply: startReply,
            openThread,
        },
    }),
);
</script>

<template>
    <Head :title="pageTitle" />

    <ChannelAnnouncers
        ref="announcers"
        :messages="displayMessages"
        :current-user-id="currentUser.id"
    />

    <!-- `relative` anchors the thread panel below `lg`, where it covers this
         whole pane — masthead included — rather than being a flex sibling
         squeezing the channel beside it: a full-screen push below `md`, a
         card-sized overlay through the tablet band (#788). -->
    <div class="relative flex min-h-0 flex-1 overflow-hidden">
        <div class="relative flex min-w-0 flex-1 flex-col">
            <ChannelHeader
                ref="header"
                :team="props.team"
                :channel="props.channel"
                :members="props.members"
                :presence-for="presenceFor"
                :is-dnd-for="isDndFor"
                :title="mastheadTitle"
                :can-manage-preferences="props.canManagePreferences"
                :can-archive="props.canArchive"
                :can-delete="props.canDelete"
                :can-leave="props.canLeave"
                :can-add-people="canAddPeople"
                :can-pin="props.canReact"
                :notification-levels="props.notificationLevels"
                :pins="props.pins"
                :pin-count="props.pinCount"
                :connection-pill="connection.pill.value"
                :scrolled="pane?.scrolled ?? false"
                :viewer-time-zone="timezone"
                @open-details="dialogs?.openDetails()"
                @archive="dialogs?.confirmArchive()"
                @delete="dialogs?.confirmDelete()"
                @leave="dialogs?.confirmLeave()"
                @add-people="dialogs?.openAddPeople()"
                @jump="jumpToMessage"
                @unpin="actions.unpinMessage"
            />

            <ChannelPane
                ref="pane"
                :team="props.team"
                :channel="props.channel"
                :messages="displayMessages"
                :server-messages="serverMessages"
                :is-self-dm="isSelfDm"
                :pending-uuids="pendingUuids"
                :queued-uuids="queuedUuids"
                :can-drop-files="props.isMember && !props.channel.isArchived"
                :presence-for="presenceFor"
                :is-dnd-for="isDndFor"
                :readers="channelReadersList"
                :active-thread-root-id="activeThreadRootId"
                :editing-message-id="composerEditingId"
                :last-read-message-id="props.lastReadMessageId ?? null"
                :register-container="setScrollContainer"
                :pinned-to-bottom="pinnedToBottom"
                :new-message-count="newMessageCount"
                :scroll-to-bottom="scrollToBottom"
                @scroll="onScroll"
                @files="(files) => dock?.addFiles(files)"
                @focus-composer="dock?.focus()"
                @mention="(member) => dock?.insertMention(member)"
            >
                <ArchivedNotice v-if="props.channel.isArchived" />

                <!-- A non-member reached this public channel by URL: the timeline
                     is read-only and the composer is replaced by a "Join channel"
                     bar in the same slot. Joining reloads the page as a member,
                     rendering the normal composer in its place. -->
                <JoinChannelBar
                    v-else-if="!props.isMember"
                    :team-slug="props.team.slug"
                    :channel-name="props.channel.name"
                    :channel-slug="props.channel.slug"
                    :member-count="props.memberCount"
                />

                <ChannelComposerDock
                    v-else
                    ref="dock"
                    :team="props.team"
                    :channel="props.channel"
                    :members="mentionableMembers"
                    :has-bots="channelHasBots"
                    :placeholder="composerPlaceholder"
                    :reply-target="replyTarget"
                    :messages="displayMessages"
                    :current-user-id="currentUser.id"
                    :pending-uuids="pendingUuids"
                    :timezone="timezone"
                    :typing-names="typingNames"
                    :scheduled-messages="props.scheduledMessages"
                    :queued-count="outbox.count.value"
                    :is-offline="!connection.isOnline.value"
                    @send="actions.send"
                    @command="actions.sendCommand"
                    @schedule="actions.scheduleMessage"
                    @typing="typing.signalTyping"
                    @cancel-reply="cancelReply"
                    @draft-change="onDraftChange"
                    @edit="actions.editMessage"
                    @editing-change="composerEditingId = $event"
                    @discard-queue="discardQueue"
                    @view-scheduled="dialogs?.openScheduled()"
                />
            </ChannelPane>
        </div>

        <Transition
            enter-active-class="transition-transform duration-200 ease-out"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition-transform duration-150 ease-in"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <ThreadPanel
                v-if="activeThreadRootId"
                :root-id="activeThreadRootId"
                :team-slug="props.team.slug"
                :channel-name="props.channel.name"
                :messages="threadMessages"
                :pending-uuids="threadPendingUuids"
                :members="mentionableMembers"
                :has-bots="channelHasBots"
                :presence-for="presenceFor"
                :is-dnd-for="isDndFor"
                :loading="threadLoading"
                :read-only="props.channel.isArchived"
                @close="closeThread"
                @send="actions.sendThreadReply"
                @typing="typing.signalTyping"
            />
        </Transition>
    </div>

    <ChannelDialogs
        ref="dialogs"
        :team="props.team"
        :channel="props.channel"
        :current-user-id="currentUser.id"
        :can-edit-channel="props.canEditChannel"
        :can-rename-channel="props.canRenameChannel"
        :timezone="timezone"
        :scheduled-messages="props.scheduledMessages"
        :forward-message="actions.forwardMessage"
        :set-reminder="actions.setReminder"
        :update-scheduled="actions.updateScheduled"
        :cancel-scheduled="actions.cancelScheduled"
    />
</template>
