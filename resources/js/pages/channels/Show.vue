<script setup lang="ts">
import { Head, InfiniteScroll, router, usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import {
    Archive,
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
} from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { update as updateChannelPreferences } from '@/actions/App/Http/Controllers/Channels/ChannelPreferenceController';
import {
    destroy as destroyMessage,
    store as storeMessage,
    update as updateMessage,
} from '@/actions/App/Http/Controllers/Channels/MessageController';
import MessageComposer from '@/components/MessageComposer.vue';
import MessageList from '@/components/MessageList.vue';
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
import { useTeamPresence } from '@/composables/useTeamPresence';
import { useTypingIndicator } from '@/composables/useTypingIndicator';
import type { TypingUser } from '@/composables/useTypingIndicator';
import type {
    Channel,
    Mention,
    Message,
    MessagePage,
    NotificationLevel,
    NotificationLevelOption,
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

// Optimistically-rendered messages awaiting confirmation, keyed by the client
// uuid the server persists. Confirmation arrives either as the reloaded server
// page or as the realtime echo of our own broadcast.
const pending = ref<Message[]>([]);

// Messages received live over the channel's private broadcast channel.
const live = ref<Message[]>([]);

// Edits and deletions applied on top of whichever copy of a message is rendered,
// keyed by client uuid. Sourced from our own optimistic mutations and from the
// MessageUpdated / MessageDeleted echoes, so every client converges in place.
const patches = ref<Map<string, Message>>(new Map());

const scrollContainer = ref<HTMLElement | null>(null);

// `Inertia::scroll` delivers messages newest-first; reverse for display.
const serverMessages = computed<Message[]>(() =>
    [...(props.messages?.data ?? [])].reverse(),
);

// Merge every source, deduping by client uuid (server wins, then live, then the
// optimistic copy) and ordering chronologically.
const displayMessages = computed<Message[]>(() => {
    const byUuid = new Map<string, Message>();

    for (const message of serverMessages.value) {
        byUuid.set(message.clientUuid, message);
    }

    for (const message of live.value) {
        if (!byUuid.has(message.clientUuid)) {
            byUuid.set(message.clientUuid, message);
        }
    }

    for (const message of pending.value) {
        if (!byUuid.has(message.clientUuid)) {
            byUuid.set(message.clientUuid, message);
        }
    }

    // Overlay edits/deletions on the rendered copy, keeping its original slot.
    for (const [uuid, patch] of patches.value) {
        if (byUuid.has(uuid)) {
            byUuid.set(uuid, patch);
        }
    }

    return [...byUuid.values()].sort((a, b) =>
        a.createdAt < b.createdAt ? -1 : a.createdAt > b.createdAt ? 1 : 0,
    );
});

const pendingUuids = computed(() =>
    pending.value.map((message) => message.clientUuid),
);

const hasMessages = computed(() => displayMessages.value.length > 0);

// Drop optimistic messages once the server page or a live echo confirms them.
const confirmedUuids = computed(
    () =>
        new Set([
            ...serverMessages.value.map((message) => message.clientUuid),
            ...live.value.map((message) => message.clientUuid),
        ]),
);

watch(confirmedUuids, (uuids) => {
    pending.value = pending.value.filter(
        (message) => !uuids.has(message.clientUuid),
    );
});

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

function appendLive(message: Message): void {
    const known =
        live.value.some((m) => m.clientUuid === message.clientUuid) ||
        serverMessages.value.some((m) => m.clientUuid === message.clientUuid);

    if (known) {
        return;
    }

    const pinned = isNearBottom();
    live.value.push(message);

    if (pinned) {
        nextTick(scrollToBottom);
    }
}

function channelName(id: string): string {
    return `channel.${id}`;
}

function applyPatch(message: Message): void {
    patches.value.set(message.clientUuid, message);
}

function subscribe(id: string): void {
    echo()
        .private(channelName(id))
        .listen('MessageSent', (message: Message) => {
            // Their message landed; stop showing them as typing.
            typing.forget(message.user.id);
            appendLive(message);
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
    markRead();
    window.addEventListener('focus', markRead);

    if (props.jumpToMessageId) {
        jumpToMessage(props.jumpToMessageId);
    }
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

// Inertia may reuse this page component when navigating between channels; move
// the subscription and reset per-channel state when the channel changes.
watch(
    () => props.channel.id,
    (newId, oldId) => {
        if (oldId) {
            unsubscribe(oldId);
        }

        live.value = [];
        pending.value = [];
        patches.value = new Map();
        typing.reset();
        notificationLevel.value = props.channel.notificationLevel;
        muted.value = props.channel.muted;
        subscribe(newId);
        markRead();
    },
);

onBeforeUnmount(() => {
    unsubscribe(props.channel.id);
    window.removeEventListener('focus', markRead);

    if (markReadTimer) {
        clearTimeout(markReadTimer);
    }

    if (highlightTimer) {
        clearTimeout(highlightTimer);
    }
});

function send(body: string, mentions: Mention[]): void {
    const clientUuid = crypto.randomUUID();

    pending.value.push({
        id: clientUuid,
        clientUuid,
        body,
        user: currentUser.value,
        createdAt: new Date().toISOString(),
        editedAt: null,
        isDeleted: false,
        mentions,
    });

    nextTick(scrollToBottom);

    router.post(
        storeMessage({ team: props.team.slug, channel: props.channel.slug })
            .url,
        { body, client_uuid: clientUuid },
        {
            preserveScroll: true,
            onError: () => {
                // The optimistic row failed to persist; roll it back and notify.
                pending.value = pending.value.filter(
                    (message) => message.clientUuid !== clientUuid,
                );
                toast.error('Your message failed to send. Please try again.');
            },
        },
    );
}

function editMessage(message: Message, body: string): void {
    const previous = patches.value.get(message.clientUuid);

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
                if (previous) {
                    patches.value.set(message.clientUuid, previous);
                } else {
                    patches.value.delete(message.clientUuid);
                }

                toast.error('Your edit failed to save. Please try again.');
            },
        },
    );
}

function deleteMessage(message: Message): void {
    const previous = patches.value.get(message.clientUuid);

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
                if (previous) {
                    patches.value.set(message.clientUuid, previous);
                } else {
                    patches.value.delete(message.clientUuid);
                }

                toast.error('Failed to delete the message. Please try again.');
            },
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

    <header
        class="flex h-12 shrink-0 items-center gap-2.5 border-b border-border px-5"
    >
        <SidebarTrigger
            class="-ml-1.5 size-8 text-muted-foreground md:hidden"
        />
        <h1 class="text-[15px] font-semibold text-foreground">
            <span class="mr-0.5 font-medium text-muted-foreground/70">#</span
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
            <p class="min-w-0 truncate text-[13px] text-muted-foreground">
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

        <DropdownMenu v-if="props.canManagePreferences || props.canArchive">
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
                        @select="(event: Event) => event.preventDefault()"
                    >
                        Mute channel
                    </DropdownMenuCheckboxItem>
                </template>
                <template v-if="props.canArchive">
                    <DropdownMenuSeparator v-if="props.canManagePreferences" />
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

    <div class="flex min-h-0 flex-1 flex-col">
        <div ref="scrollContainer" class="min-h-0 flex-1 overflow-y-auto">
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
                    @edit="editMessage"
                    @delete="deleteMessage"
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
                <p class="mt-2.5 text-[15px] font-semibold text-foreground">
                    No messages yet
                </p>
                <p class="text-[13.5px] text-muted-foreground">
                    Be the first to say something in #{{ props.channel.name }}.
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
            <TypingIndicator :names="typingNames" class="mx-5 shrink-0" />

            <MessageComposer
                :channel-name="props.channel.name"
                :members="mentionableMembers"
                @send="send"
                @typing="onTyping"
            />
        </template>
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
