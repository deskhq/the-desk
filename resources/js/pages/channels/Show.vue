<script setup lang="ts">
import { Head, InfiniteScroll, router, usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import {
    Archive,
    ArrowUp,
    AtSign,
    BellMinus,
    BellOff,
    EllipsisVertical,
} from '@lucide/vue';
import type { AcceptableValue } from 'reka-ui';
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
    readThread as markThreadReadAction,
    show as showChannel,
} from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { update as updateChannelPreferences } from '@/actions/App/Http/Controllers/Channels/ChannelPreferenceController';
import {
    destroy as destroyMessage,
    store as storeMessage,
    update as updateMessage,
} from '@/actions/App/Http/Controllers/Channels/MessageController';
import MessageComposer from '@/components/MessageComposer.vue';
import MessageList from '@/components/MessageList.vue';
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
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import {
    useMessageStream,
    optimisticMessage,
} from '@/composables/useMessageStream';
import { useTeamPresence } from '@/composables/useTeamPresence';
import { useTypingIndicator } from '@/composables/useTypingIndicator';
import type { TypingUser } from '@/composables/useTypingIndicator';
import { shouldFlagThreadUnread } from '@/lib/shouldFlagThreadUnread';
import { unreadDividerMessageId } from '@/lib/unreadDivider';
import type {
    Channel,
    Mention,
    Message,
    MessagePage,
    NotificationLevel,
    NotificationLevelOption,
    Thread,
} from '@/types';

const props = defineProps<{
    team: { id: string; name: string; slug: string };
    channel: Channel;
    messages: MessagePage;
    members: Mention[];
    canArchive: boolean;
    canManagePreferences: boolean;
    notificationLevels: NotificationLevelOption[];
    jumpToMessageId?: string | null;
    // The viewer's read pointer at load time, used to place the "New messages"
    // divider; null when the channel has never been read.
    lastReadMessageId?: string | null;
    // The open thread's root, loaded on demand keyed by `?thread=`.
    thread?: Thread | null;
    // The open thread's replies, a reverse-infinite-scroll page that grows as
    // older replies load. Empty when no thread is open.
    threadReplies: MessagePage;
}>();

const page = usePage();

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

function onTyping(): void {
    typing.signalTyping(currentUser.value);
}

// You can't @mention yourself; drop the current user from the composer list.
const mentionableMembers = computed(() =>
    props.members.filter((member) => member.id !== currentUser.value.id),
);

// A team Admin+ may delete anyone's message in the channel (moderation).
const canModerate = computed(() =>
    ['admin', 'owner'].includes(page.props.currentTeam?.role ?? ''),
);

// Distance (px) from the bottom within which the view stays pinned to newest,
// so an incoming message never yanks a user who is reading older history.
const NEAR_BOTTOM_THRESHOLD = 120;

const scrollContainer = ref<HTMLElement | null>(null);

// `Inertia::scroll` delivers messages newest-first; reverse for display.
const serverMessages = computed<Message[]>(() =>
    [...(props.messages?.data ?? [])].reverse(),
);

// The main channel timeline: optimistic sends + live echoes + edit/delete
// patches, all merged over the server page and deduped by client uuid.
const mainStream = useMessageStream(serverMessages);
const displayMessages = mainStream.displayMessages;
const pendingUuids = mainStream.pendingUuids;

const hasMessages = computed(() => displayMessages.value.length > 0);

// The open thread's root, kept client-side so a partial reload that omits the
// optional `thread` prop can't drop it. The panel runs its own stream instance
// over the root plus its paginated replies.
const activeThreadRootId = ref<string | null>(null);
const threadData = ref<Thread | null>(null);
const threadLoading = ref(false);

// The reply page arrives newest-first (older replies page in on scroll-up); the
// root is the thread's oldest message, so it leads the reversed list. The stream
// then re-sorts by timestamp, keeping the root pinned to the top.
const threadServerMessages = computed<Message[]>(() =>
    threadData.value
        ? [
              threadData.value.root,
              ...[...(props.threadReplies?.data ?? [])].reverse(),
          ]
        : [],
);

const threadStream = useMessageStream(threadServerMessages);
const threadMessages = threadStream.displayMessages;
const threadPendingUuids = threadStream.pendingUuids;

function isNearBottom(): boolean {
    const el = scrollContainer.value;

    if (!el) {
        return true;
    }

    return (
        el.scrollHeight - el.scrollTop - el.clientHeight <=
        NEAR_BOTTOM_THRESHOLD
    );
}

function scrollToBottom(): void {
    const el = scrollContainer.value;

    if (el) {
        el.scrollTop = el.scrollHeight;
    }
}

// The message to briefly highlight after a search jump. The server windows the
// initial page so the target is loaded; we scroll it into view and clear the
// highlight after a short beat.
const highlightedMessageId = ref<string | null>(null);
let highlightTimer: ReturnType<typeof setTimeout> | null = null;

function jumpToMessage(id: string): void {
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

// The message the "New messages" divider sits above, frozen at the moment the
// channel opens: the read pointer keeps advancing as the user reads, but the
// boundary stays put until they leave the channel. Recomputed on open and on
// every channel switch from the read pointer the server captured before its
// debounced advance.
const unreadDividerId = ref<string | null>(null);
const unreadDividerInView = ref(false);
let unreadObserver: IntersectionObserver | null = null;

// The floating "jump to new messages" pill shows only while there's a boundary
// the user hasn't scrolled to yet.
const showJumpToUnread = computed(
    () => unreadDividerId.value !== null && !unreadDividerInView.value,
);

function computeUnreadDivider(): void {
    unreadDividerId.value = unreadDividerMessageId(
        displayMessages.value,
        props.lastReadMessageId ?? null,
        currentUser.value.id,
    );

    observeUnreadDivider();
}

// Watch the divider element so the jump pill hides once it scrolls into view.
function observeUnreadDivider(): void {
    unreadObserver?.disconnect();
    unreadObserver = null;
    unreadDividerInView.value = false;

    if (unreadDividerId.value === null) {
        return;
    }

    nextTick(() => {
        const el = document.getElementById('unread-divider');
        const root = scrollContainer.value;

        if (!el || !root) {
            return;
        }

        unreadObserver = new IntersectionObserver(
            ([entry]) => {
                unreadDividerInView.value = entry.isIntersecting;
            },
            { root },
        );
        unreadObserver.observe(el);
    });
}

function scrollToUnread(): void {
    document
        .getElementById('unread-divider')
        ?.scrollIntoView({ block: 'center', behavior: 'smooth' });
}

// Append a message to the main timeline, keeping the view pinned to newest only
// when the reader was already near the bottom.
function appendLiveMain(message: Message): void {
    const pinned = isNearBottom();

    if (mainStream.appendLive(message) && pinned) {
        nextTick(scrollToBottom);
    }
}

// Route a broadcast message to the timeline it belongs to. A thread reply stays
// out of the main timeline unless it was explicitly "also sent to channel"; the
// open thread only appends replies belonging to its root. The two are not
// exclusive — a sent-to-channel reply in the open thread lands in both.
function routeIncoming(message: Message): void {
    if (message.threadRootId === null || message.sentToChannel) {
        appendLiveMain(message);
    }

    if (
        message.threadRootId !== null &&
        message.threadRootId === activeThreadRootId.value
    ) {
        threadStream.appendLive(message);
    }

    // A thread reply either keeps the open, focused thread read as it streams in,
    // or raises the unread dot on its root back in the main timeline.
    if (message.threadRootId !== null) {
        const viewingThreadFocused =
            message.threadRootId === activeThreadRootId.value &&
            document.hasFocus();

        if (viewingThreadFocused) {
            markThreadRead();

            return;
        }

        const root = displayMessages.value.find(
            (candidate) => candidate.id === message.threadRootId,
        );

        if (
            shouldFlagThreadUnread({
                isReply: true,
                isOwnReply: message.user.id === currentUser.value.id,
                isFollowedThread: root?.threadFollowed ?? false,
                isViewingThreadFocused: false,
                isSuppressed: threadUnreadSuppressed.value,
            })
        ) {
            mainStream.patchThreadState(message.threadRootId, {
                threadUnread: true,
            });
        }
    }
}

// Advance the open thread's read pointer so its unread dot clears, mirroring the
// channel's markRead: debounced, gated on focus, and optimistically clearing the
// dot on the root back in the main timeline.
let threadReadTimer: ReturnType<typeof setTimeout> | null = null;

function markThreadRead(): void {
    const rootId = activeThreadRootId.value;

    if (!rootId || !document.hasFocus()) {
        return;
    }

    if (threadReadTimer) {
        clearTimeout(threadReadTimer);
    }

    threadReadTimer = setTimeout(() => {
        router.post(
            markThreadReadAction({
                team: props.team.slug,
                channel: props.channel.slug,
                message: rootId,
            }).url,
            {},
            { preserveScroll: true, preserveState: true, only: ['channels'] },
        );

        mainStream.patchThreadState(rootId, { threadUnread: false });
    }, 400);
}

function channelName(id: string): string {
    return `channel.${id}`;
}

// An edit or deletion may touch either timeline (or both, for a sent-to-channel
// reply); patch both streams, since a patch is ignored where the message isn't
// rendered.
function applyPatch(message: Message): void {
    mainStream.applyPatch(message);
    threadStream.applyPatch(message);
}

function subscribe(id: string): void {
    echo()
        .private(channelName(id))
        .listen('MessageSent', (message: Message) => {
            // Their message landed; stop showing them as typing.
            typing.forget(message.user.id);
            routeIncoming(message);
            // Keep the open, focused channel read as new messages arrive.
            markRead();
        })
        .listen('MessageUpdated', (message: Message) => {
            applyPatch(message);
        })
        .listen('MessageDeleted', (message: Message) => {
            applyPatch(message);
        })
        .listenForWhisper('typing', (user: TypingUser) => {
            typing.receiveTyping(user);
        });
}

function unsubscribe(id: string): void {
    echo().leave(channelName(id));
}

// Advance the read pointer for the open channel so its sidebar badge clears.
// Debounced and gated on tab focus: a channel is only "read" while the user is
// actually looking at it, and a burst of arriving messages collapses to one
// request. The redirect refreshes just the shared `channels` prop.
let markReadTimer: ReturnType<typeof setTimeout> | null = null;

function markRead(): void {
    if (!document.hasFocus()) {
        return;
    }

    if (markReadTimer) {
        clearTimeout(markReadTimer);
    }

    markReadTimer = setTimeout(() => {
        router.post(
            markChannelRead({
                team: props.team.slug,
                channel: props.channel.slug,
            }).url,
            {},
            { preserveScroll: true, preserveState: true, only: ['channels'] },
        );
    }, 400);
}

onMounted(() => {
    subscribe(props.channel.id);
    computeUnreadDivider();
    markRead();
    window.addEventListener('focus', markRead);
    window.addEventListener('focus', markThreadRead);

    if (props.jumpToMessageId) {
        jumpToMessage(props.jumpToMessageId);
    }

    // Reopen a deep-linked thread: the `thread` prop is already resolved from
    // the `?thread=` param on the initial load, so adopt it directly.
    if (props.thread) {
        activeThreadRootId.value = props.thread.root.id;
        threadData.value = props.thread;
    }
});

// The thread prop only arrives on a partial reload that requests it; copy it
// into client state (guarded to the thread we're actually opening) so a later
// full visit that omits the optional prop can't blank the open panel.
watch(
    () => props.thread,
    (thread) => {
        if (thread && thread.root.id === activeThreadRootId.value) {
            threadData.value = thread;
            threadLoading.value = false;
        }
    },
);

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

// Inertia may reuse this page component when navigating between channels; move
// the subscription and reset per-channel state when the channel changes.
watch(
    () => props.channel.id,
    (newId, oldId) => {
        if (oldId) {
            unsubscribe(oldId);
        }

        mainStream.reset();
        resetThreadPanel();
        replyTarget.value = null;
        typing.reset();
        notificationLevel.value = props.channel.notificationLevel;
        muted.value = props.channel.muted;
        subscribe(newId);
        computeUnreadDivider();
        markRead();
    },
);

onBeforeUnmount(() => {
    unsubscribe(props.channel.id);
    unreadObserver?.disconnect();
    window.removeEventListener('focus', markRead);
    window.removeEventListener('focus', markThreadRead);

    if (markReadTimer) {
        clearTimeout(markReadTimer);
    }

    if (threadReadTimer) {
        clearTimeout(threadReadTimer);
    }

    if (highlightTimer) {
        clearTimeout(highlightTimer);
    }
});

// The message the composer is currently quoting, or null for a normal send.
const replyTarget = ref<Message | null>(null);

function startReply(message: Message): void {
    replyTarget.value = message;
}

function cancelReply(): void {
    replyTarget.value = null;
}

function send(body: string, mentions: Mention[]): void {
    const clientUuid = crypto.randomUUID();
    const target = replyTarget.value;

    // The optimistic row mirrors the parent quote so the reference renders
    // immediately; the server echo replaces it, keyed on the same client uuid.
    mainStream.addPending(
        optimisticMessage({
            clientUuid,
            body,
            author: currentUser.value,
            mentions,
            replyTo: target,
        }),
    );

    cancelReply();
    nextTick(scrollToBottom);

    router.post(
        storeMessage({ team: props.team.slug, channel: props.channel.slug })
            .url,
        { body, client_uuid: clientUuid, reply_to_id: target?.id ?? null },
        {
            preserveScroll: true,
            onError: () => {
                // The optimistic row failed to persist; roll it back and notify.
                mainStream.removePending(clientUuid);
                toast.error('Your message failed to send. Please try again.');
            },
        },
    );
}

// Post a reply into the open thread. It renders optimistically in the panel and,
// when "also send to channel" is checked, in the main timeline too.
function sendThreadReply(
    body: string,
    mentions: Mention[],
    sendToChannel?: boolean,
): void {
    const rootId = activeThreadRootId.value;

    if (!rootId) {
        return;
    }

    const clientUuid = crypto.randomUUID();
    const optimistic = optimisticMessage({
        clientUuid,
        body,
        author: currentUser.value,
        mentions,
        threadRootId: rootId,
        sentToChannel: sendToChannel ?? false,
    });

    threadStream.addPending(optimistic);

    // Replying makes the viewer a follower of the thread and means they've seen
    // it, so keep the root's affordance in the main timeline dot-free.
    mainStream.patchThreadState(rootId, {
        threadFollowed: true,
        threadUnread: false,
    });

    if (sendToChannel) {
        appendPendingMain(optimistic);
    }

    router.post(
        storeMessage({ team: props.team.slug, channel: props.channel.slug })
            .url,
        {
            body,
            client_uuid: clientUuid,
            thread_root_id: rootId,
            sent_to_channel: sendToChannel ?? false,
        },
        {
            preserveScroll: true,
            onError: () => {
                threadStream.removePending(clientUuid);

                if (sendToChannel) {
                    mainStream.removePending(clientUuid);
                }

                toast.error('Your reply failed to send. Please try again.');
            },
        },
    );
}

// Add an optimistic row to the main timeline, keeping the pinned-to-bottom rule.
function appendPendingMain(message: Message): void {
    const pinned = isNearBottom();
    mainStream.addPending(message);

    if (pinned) {
        nextTick(scrollToBottom);
    }
}

function editMessage(message: Message, body: string): void {
    const previousMain = mainStream.getPatch(message.clientUuid);
    const previousThread = threadStream.getPatch(message.clientUuid);

    // Optimistically show the edit; the broadcast echo later confirms it.
    applyPatch({ ...message, body, editedAt: new Date().toISOString() });

    router.patch(
        updateMessage({
            team: props.team.slug,
            channel: props.channel.slug,
            message: message.id,
        }).url,
        { body },
        {
            preserveScroll: true,
            onError: () => {
                mainStream.restorePatch(message.clientUuid, previousMain);
                threadStream.restorePatch(message.clientUuid, previousThread);
                toast.error('Your edit failed to save. Please try again.');
            },
        },
    );
}

function deleteMessage(message: Message): void {
    const previousMain = mainStream.getPatch(message.clientUuid);
    const previousThread = threadStream.getPatch(message.clientUuid);

    // Optimistically show the tombstone; the broadcast echo later confirms it.
    applyPatch({ ...message, body: '', isDeleted: true });

    router.delete(
        destroyMessage({
            team: props.team.slug,
            channel: props.channel.slug,
            message: message.id,
        }).url,
        {
            preserveScroll: true,
            onError: () => {
                mainStream.restorePatch(message.clientUuid, previousMain);
                threadStream.restorePatch(message.clientUuid, previousThread);
                toast.error('Failed to delete the message. Please try again.');
            },
        },
    );
}

// Reset the panel's client state without navigating. Used when switching
// channels, where the URL has already moved off any open thread.
function resetThreadPanel(): void {
    activeThreadRootId.value = null;
    threadData.value = null;
    threadLoading.value = false;
    threadStream.reset();
}

// Open the thread rooted at a message by putting `?thread=<root>` in the URL, so
// the root (`thread`) and the first page of replies (`threadReplies`) load and
// the reply InfiniteScroll's paging requests carry the root. `reset` clears any
// previous thread's merged replies; a skeleton shows until the root arrives.
function openThread(rootId: string): void {
    if (activeThreadRootId.value === rootId) {
        return;
    }

    activeThreadRootId.value = rootId;
    threadStream.reset();
    threadData.value = null;
    threadLoading.value = true;

    // Opening the thread clears its dot straight away; the read pointer advances
    // once the replies load (and again on focus / as new replies stream in).
    mainStream.patchThreadState(rootId, { threadUnread: false });

    router.get(
        showChannel(
            { team: props.team.slug, channel: props.channel.slug },
            { query: { thread: rootId } },
        ).url,
        {},
        {
            only: ['thread', 'threadReplies'],
            reset: ['threadReplies'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                threadLoading.value = false;
                markThreadRead();
            },
        },
    );
}

// Close the thread: drop `?thread=` from the URL and reset the panel.
function closeThread(): void {
    if (activeThreadRootId.value === null) {
        return;
    }

    resetThreadPanel();

    router.get(
        showChannel({ team: props.team.slug, channel: props.channel.slug }).url,
        {},
        {
            only: ['thread', 'threadReplies'],
            reset: ['threadReplies'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

// The member's own notification preferences for this channel, seeded from the
// server and reseeded on every channel switch. Changes are saved optimistically
// (the sidebar reloads to reflect the new badge/dimming state) and rolled back
// if the request fails.
const notificationLevel = ref<NotificationLevel>(
    props.channel.notificationLevel,
);
const muted = ref<boolean>(props.channel.muted);

// Thread-unread dots are silenced under the same rule as the sidebar's unread
// badge: a muted channel or any level below "all". Mirrors the server's
// suppression so a live dot and a navigation-time dot agree.
const threadUnreadSuppressed = computed(
    () => muted.value || notificationLevel.value !== 'all',
);

function savePreferences(rollback: () => void): void {
    router.patch(
        updateChannelPreferences({
            team: props.team.slug,
            channel: props.channel.slug,
        }).url,
        { muted: muted.value, notification_level: notificationLevel.value },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['channels'],
            onError: () => {
                rollback();
                toast.error(
                    'Failed to update notification preferences. Please try again.',
                );
            },
        },
    );
}

function onNotificationLevelChange(value: AcceptableValue): void {
    const previous = notificationLevel.value;
    notificationLevel.value = value as NotificationLevel;
    savePreferences(() => {
        notificationLevel.value = previous;
    });
}

function onMuteChange(value: boolean): void {
    const previous = muted.value;
    muted.value = value;
    savePreferences(() => {
        muted.value = previous;
    });
}

// A compact header cue for the member's non-default notification state (muted or
// a quieted level); the "all" default shows nothing to keep the header clean.
const notificationStatus = computed(() => {
    if (muted.value) {
        return { icon: BellOff, label: 'Muted' };
    }

    if (notificationLevel.value === 'nothing') {
        return { icon: BellMinus, label: 'Notifications off' };
    }

    if (notificationLevel.value === 'mentions') {
        return { icon: AtSign, label: 'Mentions only' };
    }

    return null;
});

// Drives the archive confirmation dialog opened from the channel header menu.
const confirmingArchive = ref(false);

function archive(): void {
    confirmingArchive.value = false;

    router.post(
        archiveChannel({ team: props.team.slug, channel: props.channel.slug })
            .url,
        {},
        {
            onError: () => {
                toast.error('Failed to archive the channel. Please try again.');
            },
        },
    );
}
</script>

<template>
    <Head :title="`#${props.channel.name}`" />

    <div class="flex min-h-0 flex-1 overflow-hidden">
        <div class="flex min-w-0 flex-1 flex-col">
            <header
                class="flex h-12 shrink-0 items-center gap-2.5 border-b border-border px-5"
            >
                <SidebarTrigger
                    class="-ml-1.5 size-8 text-muted-foreground md:hidden"
                />
                <h1 class="text-[15px] font-semibold text-foreground">
                    <span class="mr-0.5 font-medium text-muted-foreground/70"
                        >#</span
                    >{{ props.channel.name }}
                </h1>
                <span
                    v-if="notificationStatus"
                    data-test="notification-status"
                    :data-status="muted ? 'muted' : notificationLevel"
                    class="inline-flex items-center text-muted-foreground"
                    :title="notificationStatus.label"
                    :aria-label="notificationStatus.label"
                >
                    <component :is="notificationStatus.icon" class="size-3.5" />
                </span>
                <template v-if="props.channel.topic">
                    <Separator orientation="vertical" class="h-4" />
                    <p
                        class="min-w-0 truncate text-[13px] text-muted-foreground"
                    >
                        {{ props.channel.topic }}
                    </p>
                </template>

                <span
                    v-if="props.channel.isArchived"
                    class="ml-1 inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground"
                >
                    <Archive class="size-3" />
                    Archived
                </span>

                <DropdownMenu
                    v-if="props.canManagePreferences || props.canArchive"
                >
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            aria-label="Channel options"
                            data-test="channel-options"
                            class="ml-auto rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                        >
                            <EllipsisVertical class="size-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <template v-if="props.canManagePreferences">
                            <DropdownMenuLabel
                                class="text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                            >
                                Notifications
                            </DropdownMenuLabel>
                            <DropdownMenuRadioGroup
                                :model-value="notificationLevel"
                                @update:model-value="onNotificationLevelChange"
                            >
                                <DropdownMenuRadioItem
                                    v-for="level in props.notificationLevels"
                                    :key="level.value"
                                    :value="level.value"
                                    :data-test="`notification-level-${level.value}`"
                                >
                                    {{ level.label }}
                                </DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                            <DropdownMenuSeparator />
                            <DropdownMenuCheckboxItem
                                :model-value="muted"
                                data-test="mute-channel"
                                @update:model-value="onMuteChange"
                                @select="
                                    (event: Event) => event.preventDefault()
                                "
                            >
                                Mute channel
                            </DropdownMenuCheckboxItem>
                        </template>
                        <template v-if="props.canArchive">
                            <DropdownMenuSeparator
                                v-if="props.canManagePreferences"
                            />
                            <DropdownMenuItem
                                data-test="archive-channel"
                                class="text-destructive focus:text-destructive"
                                @select="confirmingArchive = true"
                            >
                                <Archive class="size-4" />
                                Archive channel
                            </DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </header>

            <div class="relative flex min-h-0 flex-1 flex-col">
                <Transition
                    enter-active-class="transition duration-150 ease-out"
                    enter-from-class="-translate-y-1 opacity-0"
                    enter-to-class="translate-y-0 opacity-100"
                    leave-active-class="transition duration-100 ease-in"
                    leave-from-class="translate-y-0 opacity-100"
                    leave-to-class="-translate-y-1 opacity-0"
                >
                    <button
                        v-if="showJumpToUnread"
                        type="button"
                        data-test="jump-to-unread"
                        class="absolute top-2.5 left-1/2 z-10 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-rose-500 px-3 py-1 text-[12px] font-semibold text-white shadow-md hover:bg-rose-600"
                        @click="scrollToUnread"
                    >
                        <ArrowUp class="size-3.5" />
                        New messages
                    </button>
                </Transition>

                <div
                    ref="scrollContainer"
                    class="min-h-0 flex-1 overflow-y-auto"
                >
                    <InfiniteScroll
                        v-if="hasMessages"
                        data="messages"
                        reverse
                        preserve-url
                    >
                        <MessageList
                            :messages="displayMessages"
                            :pending-uuids="pendingUuids"
                            :current-user-id="currentUser.id"
                            :can-moderate="canModerate"
                            :online-ids="onlineIds"
                            :highlight-message-id="highlightedMessageId"
                            :unread-divider-id="unreadDividerId"
                            :active-thread-root-id="activeThreadRootId"
                            @edit="editMessage"
                            @delete="deleteMessage"
                            @reply="startReply"
                            @open-thread="openThread"
                            @jump="jumpToMessage"
                        />
                    </InfiniteScroll>

                    <div
                        v-else
                        class="flex h-full flex-col items-center justify-center gap-1"
                    >
                        <div
                            class="flex size-14 items-center justify-center rounded-2xl border border-border bg-muted text-2xl font-semibold text-muted-foreground"
                            aria-hidden="true"
                        >
                            #
                        </div>
                        <p
                            class="mt-2.5 text-[15px] font-semibold text-foreground"
                        >
                            No messages yet
                        </p>
                        <p class="text-[13.5px] text-muted-foreground">
                            Be the first to say something in #{{
                                props.channel.name
                            }}.
                        </p>
                    </div>
                </div>

                <div
                    v-if="props.channel.isArchived"
                    data-test="archived-notice"
                    class="m-5 shrink-0 rounded-lg border border-border bg-muted/40 px-4 py-3 text-center text-[13px] text-muted-foreground"
                >
                    This channel is archived. It's read-only, but its history is
                    preserved.
                </div>

                <template v-else>
                    <TypingIndicator
                        :names="typingNames"
                        class="mx-5 shrink-0"
                    />

                    <MessageComposer
                        :channel-name="props.channel.name"
                        :members="mentionableMembers"
                        :reply-target="replyTarget"
                        @send="send"
                        @typing="onTyping"
                        @cancel-reply="cancelReply"
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
                :channel-name="props.channel.name"
                :messages="threadMessages"
                :pending-uuids="threadPendingUuids"
                :members="mentionableMembers"
                :current-user-id="currentUser.id"
                :can-moderate="canModerate"
                :online-ids="onlineIds"
                :loading="threadLoading"
                :read-only="props.channel.isArchived"
                @close="closeThread"
                @send="sendThreadReply"
                @edit="editMessage"
                @delete="deleteMessage"
                @typing="onTyping"
                @jump="jumpToMessage"
            />
        </Transition>
    </div>

    <Dialog v-model:open="confirmingArchive">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Archive #{{ props.channel.name }}</DialogTitle>
                <DialogDescription>
                    The channel becomes read-only and leaves the sidebar. Its
                    messages are kept and stay searchable.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary"> Cancel </Button>
                </DialogClose>

                <Button
                    data-test="archive-channel-confirm"
                    variant="destructive"
                    @click="archive"
                >
                    Archive
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
