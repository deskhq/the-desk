<script setup lang="ts">
import { Head, InfiniteScroll, router, usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { Archive, EllipsisVertical } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';
import { archive as archiveChannel } from '@/actions/App/Http/Controllers/Channels/ChannelController';
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
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useTeamPresence } from '@/composables/useTeamPresence';
import { useTypingIndicator } from '@/composables/useTypingIndicator';
import type { TypingUser } from '@/composables/useTypingIndicator';
import type { Channel, Mention, Message, MessagePage } from '@/types';

const props = defineProps<{
    team: { id: string; name: string; slug: string };
    channel: Channel;
    messages: MessagePage;
    members: Mention[];
    canArchive: boolean;
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

onMounted(() => {
    subscribe(props.channel.id);
});

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
        subscribe(newId);
    },
);

onBeforeUnmount(() => {
    unsubscribe(props.channel.id);
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

        <DropdownMenu v-if="props.canArchive">
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
            <DropdownMenuContent align="end">
                <DropdownMenuItem
                    data-test="archive-channel"
                    class="text-destructive focus:text-destructive"
                    @select="confirmingArchive = true"
                >
                    <Archive class="size-4" />
                    Archive channel
                </DropdownMenuItem>
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
