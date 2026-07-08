<script setup lang="ts">
import { Head, InfiniteScroll, router, usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';
import { store as storeMessage } from '@/actions/App/Http/Controllers/Channels/MessageController';
import MessageComposer from '@/components/MessageComposer.vue';
import MessageList from '@/components/MessageList.vue';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { Channel, Message, MessagePage } from '@/types';

const props = defineProps<{
    team: { id: string; name: string; slug: string };
    channel: Channel;
    messages: MessagePage;
}>();

const page = usePage();

const currentUser = computed(() => ({
    id: String(page.props.auth.user.id),
    name: page.props.auth.user.name,
}));

// Distance (px) from the bottom within which the view stays pinned to newest,
// so an incoming message never yanks a user who is reading older history.
const NEAR_BOTTOM_THRESHOLD = 120;

// Optimistically-rendered messages awaiting confirmation, keyed by the client
// uuid the server persists. Confirmation arrives either as the reloaded server
// page or as the realtime echo of our own broadcast.
const pending = ref<Message[]>([]);

// Messages received live over the channel's private broadcast channel.
const live = ref<Message[]>([]);

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

function subscribe(id: string): void {
    echo()
        .private(channelName(id))
        .listen('MessageSent', (message: Message) => {
            appendLive(message);
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
        subscribe(newId);
    },
);

onBeforeUnmount(() => {
    unsubscribe(props.channel.id);
});

function send(body: string): void {
    const clientUuid = crypto.randomUUID();

    pending.value.push({
        id: clientUuid,
        clientUuid,
        body,
        user: currentUser.value,
        createdAt: new Date().toISOString(),
        editedAt: null,
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

        <MessageComposer :channel-name="props.channel.name" @send="send" />
    </div>
</template>
