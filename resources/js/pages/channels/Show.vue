<script setup lang="ts">
import { Head, InfiniteScroll, router, usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { ArrowUp, Upload, WifiOff } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';
import {
    archive as archiveChannel,
    read as markChannelRead,
} from '@/actions/App/Http/Controllers/Channels/ChannelController';
import AddDirectMessagePeopleModal from '@/components/AddDirectMessagePeopleModal.vue';
import ChannelEmptyState from '@/components/ChannelEmptyState.vue';
import ChannelMasthead from '@/components/ChannelMasthead.vue';
import ForwardMessageDialog from '@/components/ForwardMessageDialog.vue';
import JoinChannelBar from '@/components/JoinChannelBar.vue';
import LeaveChannelModal from '@/components/LeaveChannelModal.vue';
import MessageComposer from '@/components/MessageComposer.vue';
import MessageList from '@/components/MessageList.vue';
import PinsPanel from '@/components/PinsPanel.vue';
import ScheduledCountChip from '@/components/ScheduledCountChip.vue';
import ScheduledMessagesDialog from '@/components/ScheduledMessagesDialog.vue';
import ScheduleMessageDialog from '@/components/ScheduleMessageDialog.vue';
import ScrollableMessageList from '@/components/ScrollableMessageList.vue';
import ThreadPanel from '@/components/ThreadPanel.vue';
import TypingIndicator from '@/components/TypingIndicator.vue';
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
import { useChannelDraft } from '@/composables/useChannelDraft';
import { useChannelPreferences } from '@/composables/useChannelPreferences';
import { useChannelRealtime } from '@/composables/useChannelRealtime';
import { useConnectionState } from '@/composables/useConnectionState';
import { useDebouncedPost } from '@/composables/useDebouncedPost';
import { useMessageActions } from '@/composables/useMessageActions';
import { useMessageAnnouncer } from '@/composables/useMessageAnnouncer';
import {
    optimisticMessage,
    useMessageStream,
} from '@/composables/useMessageStream';
import { useScrollPin } from '@/composables/useScrollPin';
import { useSendFailureAnnouncer } from '@/composables/useSendFailureAnnouncer';
import { useTeamPresence } from '@/composables/useTeamPresence';
import { useThreadPanel } from '@/composables/useThreadPanel';
import { useTimezone } from '@/composables/useTimezone';
import { useTranslations } from '@/composables/useTranslations';
import { useTypingIndicator } from '@/composables/useTypingIndicator';
import type { TypingUser } from '@/composables/useTypingIndicator';
import { useUnreadDivider } from '@/composables/useUnreadDivider';
import { formatDayLabel } from '@/lib/datetime';
import { groupDmMastheadName } from '@/lib/groupDm';
import { createOutbox } from '@/lib/outbox';
import { buildTimelineItems } from '@/lib/timeline';
import {
    isDividerVisible,
    shouldShowUnreadJump,
    timelineItemIndexForMessage,
    unreadDividerIndex,
} from '@/lib/virtualTimeline';
import type {
    Channel,
    ChannelReader,
    Mention,
    Message,
    MessagePage,
    NotificationLevelOption,
    ScheduledMessage,
    Thread,
} from '@/types';
import type { ForwardTarget } from '@/types/forward';
import type { PersonRef } from '@/types/people';

const props = defineProps<{
    team: { id: string; name: string; slug: string };
    channel: Channel;
    messages: MessagePage;
    members: Mention[];
    canArchive: boolean;
    canManagePreferences: boolean;
    // Whether the viewer may leave the channel (a member of a standard channel
    // that isn't #general); drives the "Leave channel" menu item and modal.
    canLeave: boolean;
    // Whether the viewer may react (member of a non-archived channel); read-only
    // reaction pills still render for a non-member browsing a public channel.
    canReact: boolean;
    // Whether the viewer already belongs to the channel. A non-member viewing a
    // public channel by URL sees a "Join channel" bar in place of the composer.
    isMember: boolean;
    // The channel's member count, surfaced in the join bar for a non-member.
    memberCount: number;
    // The channel's pinned-message count, driving the masthead badge on first
    // load; kept live from the MessagePinned broadcast thereafter.
    pinCount: number;
    // The channel's pinned messages, most-recently-pinned first, feeding the pins
    // popover. Refreshed via a partial reload when the panel opens or a pin
    // changes; each row is a full Message (its `pin` carries the attribution).
    pins: Message[];
    notificationLevels: NotificationLevelOption[];
    jumpToMessageId?: string | null;
    // The viewer's read pointer at load time, used to place the "New messages"
    // divider; null when the channel has never been read.
    lastReadMessageId?: string | null;
    // Read positions of the channel's other members who share read receipts,
    // seeding the "Seen by" affordance; later advances arrive via MessageRead.
    channelReaders: ChannelReader[];
    // The open thread's root, loaded on demand keyed by `?thread=`.
    thread?: Thread | null;
    // The open thread's replies, a reverse-infinite-scroll page that grows as
    // older replies load. Empty when no thread is open.
    threadReplies: MessagePage;
    // The viewer's own pending scheduled messages for this channel, soonest
    // first, feeding the composer's "Scheduled" affordance.
    scheduledMessages: ScheduledMessage[];
}>();

const page = usePage();

const { t } = useTranslations();

const currentUser = computed(() => ({
    id: String(page.props.auth.user.id),
    name: page.props.auth.user.name,
}));

// Peers currently composing on this channel, driven by `typing` client
// whispers over the same private channel as the message events.
const typing = useTypingIndicator((user: TypingUser) => {
    echo().private(channelName(props.channel.id)).whisper('typing', user);
});

const typingNames = typing.typingNames;

// Live roster of team members currently online, driving the presence dots on
// message avatars. Follows the team across channel switches.
const { onlineIds } = useTeamPresence(() => props.team.id);

// Read positions of the channel's other members who share read receipts, keyed
// by user id. Seeded from the server prop and kept current from the MessageRead
// broadcast, driving the "Seen by" affordance under the newest message.
const readers = ref(new Map<string, ChannelReader>());

function seedReaders(): void {
    readers.value = new Map(
        props.channelReaders.map((reader) => [reader.user.id, reader]),
    );
}

const channelReadersList = computed(() => Array.from(readers.value.values()));

function onTyping(): void {
    typing.signalTyping(currentUser.value);
}

// You can't @mention yourself; drop the current user from the composer list.
const mentionableMembers = computed(() =>
    props.members.filter((member) => member.id !== currentUser.value.id),
);

// A direct message renders viewer-relative: no "#", the other participant's
// name (the viewer's own self-DM reads "You"), or a group's participant-joined
// name. Drives the `<Head>` title, the masthead, and the empty state's
// viewer-relative copy; the masthead owns the DM avatar and facepile itself.
const isSelfDm = computed(
    () =>
        props.channel.isDirect &&
        props.channel.dmUserId === currentUser.value.id,
);
const mastheadTitle = computed(() => {
    if (props.channel.isGroupDirect) {
        return (
            groupDmMastheadName(props.channel.dmParticipants ?? []) ||
            t('Group conversation')
        );
    }

    return isSelfDm.value ? t('You') : props.channel.name;
});

// The viewer may add people to any DM they belong to; grows a 1:1 into a group
// or a group further. Drives the masthead's "Add people" button and its modal.
const canAddPeople = computed(() => props.channel.isDirect && props.isMember);
const addingPeople = ref(false);

// A DM's composer addresses the conversation by its participant name rather than
// a "#channel", so its placeholder overrides the composer's channel default.
const composerPlaceholder = computed(() =>
    props.channel.isDirect
        ? t('Message :name', { name: mastheadTitle.value })
        : undefined,
);

// A team Admin+ may delete anyone's message in the channel (moderation).
const canModerate = computed(() =>
    ['admin', 'owner'].includes(page.props.currentTeam?.role ?? ''),
);

const scrollContainer = ref<HTMLElement | null>(null);
// `ScrollableMessageList` owns the scroll element; this points our ref at it so
// `useScrollPin`, `useUnreadDivider`, and the virtualized `MessageList` all bind
// to the very same node.
const setScrollContainer = (el: HTMLElement | null): void => {
    scrollContainer.value = el;
};

// The windowed timeline exposes `scrollToIndex` so off-screen jump targets can be
// brought into view; Inertia's `<InfiniteScroll>` (driven manually) exposes the
// older-page fetch. Both are read through template refs.
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

// The virtualizer's visible render-item window, surfaced by `MessageList` so the
// unread-jump pill can be derived without a DOM `IntersectionObserver`.
const timelineRange = ref<{ startIndex: number; endIndex: number } | null>(
    null,
);

// True while an older page is being fetched, gating the virtualizer's top-load
// trigger so a burst of range updates can't stack duplicate requests. Cleared
// once the merged page grows (older rows have landed).
const loadingOlder = ref(false);

// In reverse mode, older history is the paginator's *next* page (the server
// returns messages newest-first), so "load older" maps to fetchNext/hasNext.
const hasOlder = (): boolean => infiniteScrollRef.value?.hasNext() ?? false;

const isLoadingOlder = (): boolean => loadingOlder.value;

/**
 * Fetch the next older page through Inertia's merge engine. The virtualizer
 * decides *when* (the reader nears the top of loaded history); Inertia handles
 * the cursor request, prepend, and scroll positioning.
 */
function loadOlderMessages(): void {
    if (loadingOlder.value || !hasOlder()) {
        return;
    }

    loadingOlder.value = true;
    infiniteScrollRef.value?.fetchNext();
}

watch(
    () => props.messages?.data?.length ?? 0,
    () => {
        loadingOlder.value = false;
    },
);

// Shared scroll/pin bookkeeping: the pinned-to-newest flag, the "N new messages"
// count while scrolled up, and the smooth jump back to the bottom. The thread
// panel runs its own instance of the same composable.
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
        messageListRef.value?.scrollToLatest(smooth ? 'smooth' : 'auto'),
});

// `Inertia::scroll` delivers messages newest-first; reverse for display.
const serverMessages = computed<Message[]>(() =>
    [...(props.messages?.data ?? [])].reverse(),
);

// The main channel timeline: optimistic sends + live echoes + edit/delete
// patches, all merged over the server page and deduped by client uuid.
const mainStream = useMessageStream(serverMessages);
const displayMessages = mainStream.displayMessages;

// Announce genuine inbound messages to screen readers via a polite live region;
// the virtualized timeline itself can't be a `role="log"` (rows unmount).
const { announcement } = useMessageAnnouncer({
    messages: () => displayMessages.value,
    currentUserId: () => currentUser.value.id,
});

// A failed optimistic send rolls its row back silently; this polite live region
// announces the failure so a screen-reader user hears it, mirroring the toast.
const { announcement: sendFailureAnnouncement, announce: announceSendFailure } =
    useSendFailureAnnouncer();
const pendingUuids = mainStream.pendingUuids;

const hasMessages = computed(() => displayMessages.value.length > 0);

// The same grouped render list the virtualized `MessageList` builds, recomputed
// here so index-based affordances (jump-to-message, the unread boundary) can map
// a message or divider to the render-item index the virtualizer scrolls to. Pure
// and cheap; `unreadDividerId` is resolved lazily by the composable below.
const timelineItems = computed(() =>
    buildTimelineItems(displayMessages.value, unreadDividerId.value ?? null),
);

// Focus the channel composer — from the empty-state welcome's "Post your first
// message" card, or a profile hover card dropping in a mention.
function focusComposer(): void {
    channelComposer.value?.focus();
}

// The thread panel's whole open → load → reset → mark-read lifecycle, with its own
// merge stream over the root plus paginated replies. It resets itself on a channel
// switch, so this page no longer juggles two stream lifecycles inline;
// `sendThreadReply` stays in `useMessageActions`, sharing this panel's stream.
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

// The message to briefly highlight after a search jump. The server windows the
// initial page so the target is loaded; we scroll it into view and clear the
// highlight after a short beat.
const highlightedMessageId = ref<string | null>(null);
let highlightTimer: ReturnType<typeof setTimeout> | null = null;

function jumpToMessage(id: string): void {
    // Bring the target's render item into the window first — with windowing its
    // element may not exist yet to scroll to — then refine and highlight once the
    // row mounts. A missing index (target not loaded) still highlights on arrival.
    const index = timelineItemIndexForMessage(timelineItems.value, id);

    if (index >= 0) {
        messageListRef.value?.scrollToIndex(index, 'center');
    }

    nextTick(() => {
        document
            .getElementById(`message-${id}`)
            ?.scrollIntoView({ block: 'center' });

        highlightedMessageId.value = id;

        if (highlightTimer) {
            clearTimeout(highlightTimer);
        }

        highlightTimer = setTimeout(() => {
            highlightedMessageId.value = null;
        }, 2000);
    });
}

// The "New messages" divider's lifecycle — freeze its position at open, refreeze
// on each channel switch, and hide the jump pill once it scrolls into view —
// lives in this composable. It reads the server page (not the live-merged list)
// so the boundary is immune to the order per-channel state resets on a switch.
const { unreadDividerId } = useUnreadDivider({
    channelId: () => props.channel.id,
    scrollContainer,
    messages: () => serverMessages.value,
    lastReadMessageId: () => props.lastReadMessageId ?? null,
    currentUserId: () => currentUser.value.id,
});

// The unread boundary's render-item index, or -1 when there's none.
const unreadIndex = computed(() => unreadDividerIndex(timelineItems.value));

// Per-visit latch: once the reader reaches the unread boundary (it scrolls into
// the window) or jumps back to the present, the "New messages" pill is dismissed
// for the rest of this channel visit. Without it the pill reappears whenever the
// (frozen) divider sits above the window again — e.g. right after Jump to present
// (#411). Both flags are refrozen alongside the divider on every channel switch.
const unreadDividerSeen = ref(false);

// Whether the boundary has ever sat above the window this visit. The reader
// "reaches" the divider only when it scrolls back into view *after* having been
// above — the transition the seen latch keys off. This guards against the initial
// pre-`scrollToBottom` render (rows start at the top, so the divider is briefly
// on screen before the open pins to the newest message) latching it prematurely.
const unreadDividerWasAbove = ref(false);

watch(
    () => props.channel.id,
    () => {
        unreadDividerSeen.value = false;
        unreadDividerWasAbove.value = false;
    },
);

// Track the boundary's position relative to the window and latch it as seen the
// moment it scrolls back into view after having been above — the reader clicking
// the pill or scrolling up to the divider.
watch([timelineRange, unreadIndex], ([range, index]) => {
    if (!range || index < 0) {
        return;
    }

    if (index < range.startIndex) {
        unreadDividerWasAbove.value = true;
    } else if (
        unreadDividerWasAbove.value &&
        isDividerVisible(index, range.startIndex, range.endIndex)
    ) {
        unreadDividerSeen.value = true;
    }
});

// Show the floating "New messages" pill while the unread boundary sits above the
// virtualizer's window and the reader hasn't reached it yet. Windowing drops the
// off-screen divider from the DOM, so this replaces the old IntersectionObserver
// with pure range math plus the per-visit seen latch. Before the first range
// lands the view is pinned to the bottom, so an existing, unseen boundary is
// necessarily above it.
const showJumpToUnread = computed(() => {
    const range = timelineRange.value;

    if (!range) {
        return unreadIndex.value >= 0 && !unreadDividerSeen.value;
    }

    return shouldShowUnreadJump(
        unreadIndex.value,
        range.startIndex,
        range.endIndex,
        unreadDividerSeen.value,
    );
});

// Scroll the unread boundary to the top of the viewport via the virtualizer,
// bringing it on screen even when it wasn't rendered.
function scrollToUnread(): void {
    if (unreadIndex.value >= 0) {
        messageListRef.value?.scrollToIndex(unreadIndex.value, 'start');
    }
}

// Return to the newest message. Jumping to present counts as reaching the unread
// boundary, so latch it — the reader is done with the "New messages" pill for
// this visit even if the frozen divider now sits above the window again (#411).
function jumpToPresent(): void {
    unreadDividerSeen.value = true;
    scrollToBottom(true);
}

// The day the topmost visible row falls in, driving the floating sticky date
// chip while the reader is scrolled up into history (design 1a). Reads the first
// dated render item at or after the window's top — a group's lead timestamp or a
// day divider's own ISO.
const stickyDayLabel = computed<string | null>(() => {
    const range = timelineRange.value;
    const items = timelineItems.value;

    if (!range || items.length === 0) {
        return null;
    }

    for (
        let i = Math.min(range.startIndex, items.length - 1);
        i < items.length;
        i += 1
    ) {
        const item = items[i];

        // A day boundary already sits at the top of the window, shown inline —
        // the chip would just duplicate it (e.g. scrolled to the very top), so
        // suppress it until the divider scrolls off and a group leads instead.
        if (item.type === 'divider' && item.variant === 'day') {
            return null;
        }

        if (item.type === 'group') {
            return formatDayLabel(item.leadCreatedAt);
        }
    }

    return null;
});

function channelName(id: string): string {
    return `channel.${id}`;
}

// Advance the read pointer for the open channel so its sidebar badge clears.
// Debounced and gated on tab focus: a channel is only "read" while the user is
// actually looking at it, and a burst of arriving messages collapses to one
// request. The redirect refreshes just the shared `channels` prop.
const readPost = useDebouncedPost(
    () => {
        router.post(
            markChannelRead({
                team: props.team.slug,
                channel: props.channel.slug,
            }).url,
            {},
            { preserveScroll: true, preserveState: true, only: ['channels'] },
        );
    },
    { delay: 400, gate: () => document.hasFocus() },
);

function markRead(): void {
    readPost.schedule();
}

// The member's own star/mute/notification-level preferences for this channel:
// seeded from the server, reseeded on every channel switch, saved optimistically
// and rolled back on error. `threadUnreadSuppressed` mirrors the server's dot
// suppression and feeds the realtime router below.
const {
    notificationLevel,
    muted,
    starred,
    threadUnreadSuppressed,
    notificationStatus,
    toggleStar,
    onNotificationLevelChange,
    onMuteChange,
} = useChannelPreferences({
    channelId: () => props.channel.id,
    channel: () => props.channel,
    teamSlug: () => props.team.slug,
    channelSlug: () => props.channel.slug,
});

// The channel-level pin count driving the masthead badge, and the pins popover
// state. The count is seeded from the server, resynced on partial reloads and
// channel switches (the prop watch below), and patched live by the MessagePinned
// broadcast; the pins list itself rides the `pins` prop, refreshed whenever the
// panel opens or a pin changes.
const pinCount = ref(props.pinCount);
watch(
    () => props.pinCount,
    (count) => {
        pinCount.value = count;
    },
);

const pinsPanelOpen = ref(false);

// Open the pins popover, pulling the freshest pins first — another member may
// have pinned or unpinned since this page loaded, and the count badge and list
// should agree with the server on open.
function openPinsPanel(): void {
    pinsPanelOpen.value = true;
    router.reload({ only: ['pins', 'pinCount'] });
}

// Jump to a pinned message from the panel, closing the popover on the way.
function jumpToPin(id: string): void {
    pinsPanelOpen.value = false;
    jumpToMessage(id);
}

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
    isThreadUnreadSuppressed: () => threadUnreadSuppressed.value,
    readers,
    isNearBottom,
    notifyAppended,
    typing,
    markRead,
    markThreadRead,
    updatePinCount: (count: number) => {
        pinCount.value = count;
    },
});

onMounted(() => {
    seedReaders();
    markRead();
    window.addEventListener('focus', markRead);
    window.addEventListener('focus', markThreadRead);

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
watch(
    () => props.jumpToMessageId,
    (id) => {
        if (id) {
            jumpToMessage(id);
        }
    },
);

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
        pinsPanelOpen.value = false;
        // Close the add-people modal so a switch to another conversation never
        // carries it over onto the wrong DM.
        addingPeople.value = false;
        seedReaders();
        markRead();
    },
);

onBeforeUnmount(() => {
    // The draft persists itself on teardown (flushOnUnmount) and the read posts
    // cancel themselves, so leaving the workspace neither loses a just-typed draft
    // nor fires a stale mark-read.
    window.removeEventListener('focus', markRead);
    window.removeEventListener('focus', markThreadRead);

    if (highlightTimer) {
        clearTimeout(highlightTimer);
    }
});

// The message the composer is currently quoting, or null for a normal send.
const replyTarget = ref<Message | null>(null);

// The id of the message the main composer is editing in place (via the ↑
// shortcut), or null. Highlights the target row in the timeline while editing.
const composerEditingId = ref<string | null>(null);

function startReply(message: Message): void {
    replyTarget.value = message;
}

function cancelReply(): void {
    replyTarget.value = null;
}

// The channel composer, so a profile hover card in the main timeline can drop a
// mention straight into it.
const channelComposer = ref<InstanceType<typeof MessageComposer> | null>(null);

function mentionInChannel(member: { id: string; name: string }): void {
    channelComposer.value?.insertMention(member);
}

// Whole-pane drag-and-drop: dropping files anywhere over the channel content
// stages them in the composer tray. A depth counter tracks nested
// dragenter/dragleave so the brass overlay doesn't flicker as the pointer
// crosses child elements. Only meaningful where the composer itself is shown.
const isDraggingFiles = ref(false);
let dragDepth = 0;

function canDropAttachments(): boolean {
    return props.isMember && !props.channel.isArchived;
}

/** Whether a drag actually carries files (vs. selected text or an element). */
function dragCarriesFiles(event: DragEvent): boolean {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}

function onPaneDragEnter(event: DragEvent): void {
    if (!canDropAttachments() || !dragCarriesFiles(event)) {
        return;
    }

    dragDepth += 1;
    isDraggingFiles.value = true;
}

function onPaneDragOver(event: DragEvent): void {
    if (!dragCarriesFiles(event)) {
        return;
    }

    // Claim every file drag so the browser never navigates to the dropped file,
    // even where we won't accept it (archived channel, non-member).
    event.preventDefault();

    if (!canDropAttachments()) {
        return;
    }

    // Show the copy cursor only where the drop will actually be staged.
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'copy';
    }
}

function onPaneDragLeave(): void {
    if (!isDraggingFiles.value) {
        return;
    }

    dragDepth -= 1;

    if (dragDepth <= 0) {
        dragDepth = 0;
        isDraggingFiles.value = false;
    }
}

function onPaneDrop(event: DragEvent): void {
    dragDepth = 0;
    isDraggingFiles.value = false;

    if (!dragCarriesFiles(event)) {
        return;
    }

    // Prevent the browser navigating to the dropped file for every file drag,
    // then only stage the files where the channel actually accepts them.
    event.preventDefault();

    if (!canDropAttachments()) {
        return;
    }

    const files = Array.from(event.dataTransfer?.files ?? []);

    if (files.length > 0) {
        channelComposer.value?.addFiles(files);
    }
}

// The message being forwarded and whether the forward dialog is open. The dialog
// picks a target channel (from the sidebar list — the channels the viewer can
// post to) and an optional note.
const forwardTarget = ref<Message | null>(null);
const forwardDialogOpen = ref(false);

const forwardableChannels = computed<Channel[]>(
    () => page.props.channels ?? [],
);

// Team members offered as DM forward targets; selecting one opens-or-creates the
// 1:1 DM on the server.
const forwardablePeople = computed<PersonRef[]>(
    () => page.props.teamMembers ?? [],
);

function openForward(message: Message): void {
    forwardTarget.value = message;
    forwardDialogOpen.value = true;
}

// Submit the forward dialog: hand the selected source and destination to the
// actions engine, then clear the target so the dialog resets.
function submitForward(payload: { target: ForwardTarget; note: string }): void {
    const source = forwardTarget.value;

    if (source) {
        actions.forwardMessage(source, payload);
    }

    forwardTarget.value = null;
}

// The member's unsent composer text is persisted per channel so it survives
// navigation, reloads and other devices. This composable owns the debounce, the
// channel-switch flush, and the flush-on-unmount; a send clears the draft
// server-side, so only manual edits flow through `onDraftChange`.
const {
    onDraftChange,
    cancel: cancelDraft,
    clear: clearDraft,
} = useChannelDraft({
    channelId: () => props.channel.id,
    teamSlug: () => props.team.slug,
    channelSlug: () => props.channel.slug,
});

// The realtime connection cue (reconnecting / back-online pill) and the offline
// outbox: sends made while the socket is down queue here and flush on recovery.
// The outbox persists per channel, so a refresh while offline keeps the queue.
const connection = useConnectionState();
const outbox = createOutbox({ storageKey: `outbox:${props.channel.id}` });

// A queue rehydrated from a previous session has no optimistic rows yet; re-add
// them so the queued sends still render in the timeline after a refresh. The
// reply quote isn't persisted, so a rehydrated row shows its body without it.
for (const item of outbox.items.value) {
    mainStream.addPending(
        optimisticMessage({
            clientUuid: item.clientUuid,
            body: item.body,
            author: currentUser.value,
            mentions: [],
        }),
    );
}

// Client uuids currently held in the outbox, so the timeline can mark each queued
// row until it flushes.
const queuedUuids = computed(() =>
    outbox.items.value.map((item) => item.clientUuid),
);

// The channel's optimistic-mutation engine: send/edit/delete/react/forward,
// thread replies, scheduling, and reminders all follow the same optimistic-apply
// → router-call → rollback-on-error shape, concentrated behind one seam.
const actions = useMessageActions({
    teamSlug: () => props.team.slug,
    channel: () => props.channel,
    currentUser: () => currentUser.value,
    isOnline: () => connection.isOnline.value,
    onSendFailure: announceSendFailure,
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

const {
    send,
    flushOutbox,
    editMessage,
    deleteMessage,
    reactToMessage,
    pinMessage,
    unpinMessage,
    sendThreadReply,
    scheduleMessage,
    updateScheduled,
    cancelScheduled,
    setReminder,
} = actions;

// Discard the whole offline queue: drop the optimistic rows and clear the outbox.
function discardQueue(): void {
    for (const item of outbox.items.value) {
        mainStream.removePending(item.clientUuid);
    }

    outbox.clear();
}

// Whenever the socket connects, flush any queued sends — including a queue
// rehydrated on load, which connects for the first time rather than reconnecting.
// Only on a genuine reconnect do we also backfill messages that landed while the
// socket was down (the stream dedupes by client uuid, so re-fetching the latest
// page adds no gaps or dupes) and confirm the flush with a toast.
connection.onConnected(({ isReconnect }) => {
    const flushed = outbox.count.value;

    flushOutbox();

    if (!isReconnect) {
        return;
    }

    router.reload({ only: ['messages'] });

    if (flushed > 0) {
        toast.success(
            flushed === 1
                ? t("Reconnected — 1 queued message sent, you're caught up.")
                : t(
                      "Reconnected — :count queued messages sent, you're caught up.",
                      { count: flushed },
                  ),
        );
    }
});

// If we mount already connected with a rehydrated queue — the socket was up
// before this page (re)loaded, so no connect event will fire — flush it now.
if (connection.isOnline.value && outbox.count.value > 0) {
    flushOutbox();
}

// The viewer's stored timezone, driving the schedule picker's presets and the
// list's "sends at" labels so a scheduled time always reads in their own zone.
const { timezone } = useTimezone();

// Whether the "Scheduled messages" management dialog is open.
const scheduledDialogOpen = ref(false);

// The message a custom-time reminder is being set for, and whether the custom
// date & time picker is open.
const reminderTargetId = ref<string | null>(null);
const reminderCustomOpen = ref(false);

// A preset was chosen from a message's reminder popover.
function remindWith(message: Message, remindAt: string): void {
    setReminder(message.id, remindAt);
}

// The viewer chose "Custom date & time…"; remember the target and open the picker.
function openCustomReminder(message: Message): void {
    reminderTargetId.value = message.id;
    reminderCustomOpen.value = true;
}

// Confirm the custom reminder time picked in the dialog.
function confirmCustomReminder(remindAt: string): void {
    if (reminderTargetId.value === null) {
        return;
    }

    setReminder(reminderTargetId.value, remindAt);
    reminderTargetId.value = null;
}

// Drives the archive confirmation dialog opened from the channel header menu.
const confirmingArchive = ref(false);

// Drives the leave-channel confirmation modal opened from the header menu.
const confirmingLeave = ref(false);

function archive(): void {
    confirmingArchive.value = false;

    router.post(
        archiveChannel({ team: props.team.slug, channel: props.channel.slug })
            .url,
        {},
        {
            onError: () => {
                toast.error(
                    t('Failed to archive the channel. Please try again.'),
                );
            },
        },
    );
}
</script>

<template>
    <Head
        :title="
            props.channel.isDirect ? mastheadTitle : `#${props.channel.name}`
        "
    />

    <div
        aria-live="polite"
        aria-atomic="true"
        class="sr-only"
        data-test="message-announcer"
    >
        {{ announcement }}
    </div>

    <div
        aria-live="polite"
        aria-atomic="true"
        class="sr-only"
        data-test="send-failure-announcer"
    >
        {{ sendFailureAnnouncement }}
    </div>

    <div class="flex min-h-0 flex-1 overflow-hidden">
        <div class="relative flex min-w-0 flex-1 flex-col">
            <ChannelMasthead
                :channel="props.channel"
                :team-slug="props.team.slug"
                :members="props.members"
                :online-ids="onlineIds"
                :title="mastheadTitle"
                :can-manage-preferences="props.canManagePreferences"
                :can-archive="props.canArchive"
                :can-leave="props.canLeave"
                :can-add-people="canAddPeople"
                :notification-levels="props.notificationLevels"
                :starred="starred"
                :muted="muted"
                :pin-count="pinCount"
                :notification-level="notificationLevel"
                :notification-status="notificationStatus"
                :connection-pill="connection.pill.value"
                @toggle-star="toggleStar"
                @notification-level-change="onNotificationLevelChange"
                @mute-change="onMuteChange"
                @archive="confirmingArchive = true"
                @leave="confirmingLeave = true"
                @add-people="addingPeople = true"
                @open-pins="openPinsPanel"
            />

            <PinsPanel
                v-if="pinsPanelOpen"
                :pins="props.pins"
                :pin-count="pinCount"
                :can-pin="props.canReact"
                :viewer-timezone="timezone"
                @close="pinsPanelOpen = false"
                @jump="jumpToPin"
                @unpin="(message) => unpinMessage(message)"
            />

            <!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions -- the pane is a file drop zone; keyboard users attach via the composer's Add-attachment button -->
            <div
                class="relative flex min-h-0 flex-1 flex-col"
                @dragenter="onPaneDragEnter"
                @dragover="onPaneDragOver"
                @dragleave="onPaneDragLeave"
                @drop="onPaneDrop"
            >
                <!-- Whole-pane drop target: dropping files anywhere over the
                     channel stages them in the composer. Pointer-events-none so
                     the drop lands on the pane's own handler, not the overlay. -->
                <div
                    v-if="isDraggingFiles && canDropAttachments()"
                    data-test="channel-drop-overlay"
                    class="pointer-events-none absolute inset-2.5 z-20 flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-brass bg-card/75 backdrop-blur-[2px]"
                >
                    <span
                        class="flex size-14 items-center justify-center rounded-full bg-foreground text-brass"
                    >
                        <Upload class="size-6" />
                    </span>
                    <span
                        class="font-serif text-2xl font-semibold text-foreground"
                    >
                        {{
                            $t('Drop to attach to #:channel', {
                                channel: props.channel.name,
                            })
                        }}
                    </span>
                    <span class="text-[13px] text-muted-foreground">
                        {{
                            $t('Up to :count files · :size MB each', {
                                count: page.props.attachments.maxPerMessage,
                                size: page.props.attachments.maxSizeMb,
                            })
                        }}
                    </span>
                </div>
                <div class="relative flex min-h-0 flex-1 flex-col">
                    <Transition
                        enter-active-class="transition duration-150 ease-out"
                        enter-from-class="-translate-y-1 opacity-0"
                        enter-to-class="translate-y-0 opacity-100"
                        leave-active-class="transition duration-100 ease-in"
                        leave-from-class="translate-y-0 opacity-100"
                        leave-to-class="-translate-y-1 opacity-0"
                    >
                        <!-- eslint-disable-next-line local/no-raw-button -- jump pill: stays raw with the deferred jump-to-latest pills (#316); the primitive does not compose as a <Transition> child here -->
                        <button
                            v-if="showJumpToUnread"
                            type="button"
                            data-test="jump-to-unread"
                            class="absolute top-2.5 left-1/2 z-10 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-rose-600 px-3 py-1 text-[12px] font-semibold text-white shadow-md hover:bg-rose-700"
                            @click="scrollToUnread"
                        >
                            <ArrowUp class="size-3.5" />
                            {{ $t('New messages') }}
                        </button>
                    </Transition>

                    <Transition
                        enter-active-class="transition duration-150 ease-out"
                        enter-from-class="-translate-y-1 opacity-0"
                        enter-to-class="translate-y-0 opacity-100"
                        leave-active-class="transition duration-100 ease-in"
                        leave-from-class="translate-y-0 opacity-100"
                        leave-to-class="-translate-y-1 opacity-0"
                    >
                        <div
                            v-if="
                                !pinnedToBottom &&
                                stickyDayLabel &&
                                !showJumpToUnread
                            "
                            data-test="sticky-date"
                            class="pointer-events-none absolute top-2.5 left-1/2 z-10 inline-flex h-6.5 -translate-x-1/2 items-center rounded-full bg-card px-3.5 text-[11.5px] font-semibold text-muted-foreground shadow-md ring-1 ring-border"
                        >
                            {{ stickyDayLabel }}
                        </div>
                    </Transition>

                    <!-- The message history is a focusable, labelled region so
                         keyboard users can Tab to it and arrow-scroll (which
                         remounts virtualized rows). `ScrollableMessageList`
                         renders the region + the jump-to-latest pill; the
                         timeline itself can't be a `role="log"` since its rows
                         unmount, so `role="region"` names it instead. -->
                    <ScrollableMessageList
                        variant="channel"
                        :region-label="$t('Message history')"
                        :register-container="setScrollContainer"
                        :pinned-to-bottom="pinnedToBottom"
                        :new-message-count="newMessageCount"
                        @scroll="onScroll"
                        @jump="jumpToPresent"
                    >
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
                                data-test="loading-older"
                                class="pointer-events-none absolute inset-x-0 top-2 z-10 flex justify-center"
                            >
                                <span
                                    class="inline-flex items-center gap-2 rounded-full bg-card px-3 py-1 text-[12px] text-muted-foreground shadow-sm ring-1 ring-border"
                                >
                                    <span
                                        aria-hidden="true"
                                        class="size-3 animate-spin rounded-full border-2 border-border border-t-foreground"
                                    />
                                    {{ $t('Loading earlier messages…') }}
                                </span>
                            </div>
                        </Transition>

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
                                :scroll-element="scrollContainer"
                                :has-older="hasOlder"
                                :is-loading-older="isLoadingOlder"
                                :messages="displayMessages"
                                :team-slug="props.team.slug"
                                :pending-uuids="pendingUuids"
                                :queued-uuids="queuedUuids"
                                :current-user-id="currentUser.id"
                                :is-direct="props.channel.isDirect"
                                :can-moderate="canModerate"
                                :can-react="props.canReact"
                                :can-pin="props.canReact"
                                :online-ids="onlineIds"
                                :readers="channelReadersList"
                                :highlight-message-id="highlightedMessageId"
                                :unread-divider-id="unreadDividerId"
                                :active-thread-root-id="activeThreadRootId"
                                :editing-message-id="composerEditingId"
                                @edit="editMessage"
                                @delete="deleteMessage"
                                @reply="startReply"
                                @forward="openForward"
                                @react="reactToMessage"
                                @pin="pinMessage"
                                @unpin="unpinMessage"
                                @remind="remindWith"
                                @remind-custom="openCustomReminder"
                                @open-thread="openThread"
                                @jump="jumpToMessage"
                                @mention="mentionInChannel"
                                @load-older="loadOlderMessages"
                                @range-change="timelineRange = $event"
                            />
                        </InfiniteScroll>

                        <ChannelEmptyState
                            v-else
                            :channel="props.channel"
                            :is-self-dm="isSelfDm"
                            :team-name="props.team.name"
                            :team-slug="props.team.slug"
                            @focus-composer="focusComposer"
                        />
                    </ScrollableMessageList>
                </div>

                <div
                    v-if="props.channel.isArchived"
                    data-test="archived-notice"
                    class="m-5 shrink-0 rounded-lg border border-border bg-muted/40 px-4 py-3 text-center text-[13px] text-muted-foreground"
                >
                    {{
                        $t(
                            "This channel is archived. It's read-only, but its history is preserved.",
                        )
                    }}
                </div>

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

                <template v-else>
                    <!-- Offline queue banner: the socket is down and the viewer's
                         sends are being held locally; they flush automatically on
                         reconnect, or can be discarded here. -->
                    <div
                        v-if="
                            !connection.isOnline.value && outbox.count.value > 0
                        "
                        data-test="offline-queue-banner"
                        class="mx-5 mb-1 flex shrink-0 items-center gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-[12.5px] font-medium text-amber-700 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-500"
                    >
                        <WifiOff class="size-3.5 shrink-0" />
                        <span class="min-w-0">
                            {{
                                outbox.count.value === 1
                                    ? $t(
                                          "You're offline. 1 message is queued and will send automatically.",
                                      )
                                    : $t(
                                          "You're offline. :count messages are queued and will send automatically.",
                                          { count: outbox.count.value },
                                      )
                            }}
                        </span>
                        <Button
                            variant="unstyled"
                            size="none"
                            type="button"
                            data-test="discard-queue"
                            class="ml-auto shrink-0 font-semibold text-rose-600 hover:text-rose-700 dark:text-rose-400 dark:hover:text-rose-300"
                            @click="discardQueue"
                        >
                            {{ $t('Discard queue') }}
                        </Button>
                    </div>

                    <TypingIndicator
                        :names="typingNames"
                        class="mx-5 shrink-0"
                    />

                    <ScheduledCountChip
                        v-if="props.scheduledMessages.length > 0"
                        :count="props.scheduledMessages.length"
                        :channel-name="props.channel.name"
                        class="mx-5 mb-2.5"
                        @view="scheduledDialogOpen = true"
                    />

                    <MessageComposer
                        ref="channelComposer"
                        :key="props.channel.id"
                        data-tour="composer"
                        :channel-name="props.channel.name"
                        :placeholder="composerPlaceholder"
                        :members="mentionableMembers"
                        :reply-target="replyTarget"
                        :initial-body="props.channel.draft ?? ''"
                        :messages="displayMessages"
                        :current-user-id="currentUser.id"
                        :pending-uuids="pendingUuids"
                        :team-slug="props.team.slug"
                        :channel-slug="props.channel.slug"
                        :max-attachment-size-mb="
                            page.props.attachments.maxSizeMb
                        "
                        :max-attachments-per-message="
                            page.props.attachments.maxPerMessage
                        "
                        allow-schedule
                        :timezone="timezone"
                        @send="
                            (body, mentions, _sendToChannel, attachmentIds) =>
                                send(body, mentions, attachmentIds)
                        "
                        @schedule="scheduleMessage"
                        @typing="onTyping"
                        @cancel-reply="cancelReply"
                        @draft-change="onDraftChange"
                        @edit="editMessage"
                        @editing-change="composerEditingId = $event"
                    />
                </template>
            </div>
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
                :current-user-id="currentUser.id"
                :can-moderate="canModerate"
                :can-react="props.canReact"
                :can-pin="props.canReact"
                :online-ids="onlineIds"
                :loading="threadLoading"
                :read-only="props.channel.isArchived"
                @close="closeThread"
                @send="sendThreadReply"
                @edit="editMessage"
                @delete="deleteMessage"
                @forward="openForward"
                @react="reactToMessage"
                @pin="pinMessage"
                @unpin="unpinMessage"
                @remind="remindWith"
                @remind-custom="openCustomReminder"
                @typing="onTyping"
                @jump="jumpToMessage"
            />
        </Transition>
    </div>

    <ForwardMessageDialog
        v-model:open="forwardDialogOpen"
        :message="forwardTarget"
        :channels="forwardableChannels"
        :people="forwardablePeople"
        :current-user-id="currentUser.id"
        @submit="submitForward"
    />

    <ScheduledMessagesDialog
        v-model:open="scheduledDialogOpen"
        :scheduled-messages="props.scheduledMessages"
        :channel-name="props.channel.name"
        :timezone="timezone"
        @update="updateScheduled"
        @cancel="cancelScheduled"
    />

    <ScheduleMessageDialog
        v-model:open="reminderCustomOpen"
        :timezone="timezone"
        :title="$t('Remind me about this')"
        :confirm-label="$t('Set reminder')"
        @confirm="confirmCustomReminder"
    />

    <Dialog v-model:open="confirmingArchive">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    $t('Archive #:channel', { channel: props.channel.name })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'The channel becomes read-only and leaves the sidebar. Its messages are kept and stay searchable.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary"> {{ $t('Cancel') }} </Button>
                </DialogClose>

                <Button
                    data-test="archive-channel-confirm"
                    variant="destructive"
                    @click="archive"
                >
                    {{ $t('Archive') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <LeaveChannelModal
        v-model:open="confirmingLeave"
        :channel="props.channel"
        :team-slug="props.team.slug"
    />

    <AddDirectMessagePeopleModal
        v-if="props.channel.isDirect"
        v-model:open="addingPeople"
        :channel="props.channel"
        :team-slug="props.team.slug"
        :current-user-id="currentUser.id"
    />
</template>
