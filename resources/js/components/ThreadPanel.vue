<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import MessageComposer from '@/components/MessageComposer.vue';
import MessageList from '@/components/MessageList.vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { Mention, Message } from '@/types';

const props = defineProps<{
    // The open thread's root id, used to key the reply scroll so switching
    // threads mounts a fresh, bottom-anchored list.
    rootId: string;
    channelName: string;
    // The root followed by its replies (oldest first), merged and deduped by the
    // parent's thread stream; empty while the thread is still loading.
    messages: Message[];
    pendingUuids?: string[];
    members: Mention[];
    currentUserId: string;
    canModerate?: boolean;
    onlineIds?: Set<string>;
    loading?: boolean;
    readOnly?: boolean;
}>();

const emit = defineEmits<{
    close: [];
    send: [body: string, mentions: Mention[], sendToChannel?: boolean];
    edit: [message: Message, body: string];
    delete: [message: Message];
    forward: [message: Message];
    typing: [];
    jump: [messageId: string];
}>();

// The root is the thread's only top-level message; everything else is a reply.
const root = computed(() =>
    props.messages.find((message) => message.threadRootId === null),
);
const hasRoot = computed(() => root.value !== undefined);
// The header shows the thread's total reply count (denormalized on the root),
// not just the replies currently paged into view.
const replyCount = computed(() => root.value?.threadReplyCount ?? 0);
const showSkeleton = computed(() => props.loading || !hasRoot.value);

// Distance (px) from the bottom within which the panel stays pinned to newest,
// matching the main timeline's behaviour.
const NEAR_BOTTOM_THRESHOLD = 120;
const scrollContainer = ref<HTMLElement | null>(null);

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

// Keep the panel pinned to the newest reply as the conversation grows, unless
// the reader has scrolled up to older replies.
watch(
    () => props.messages.length,
    () => {
        if (isNearBottom()) {
            nextTick(scrollToBottom);
        }
    },
);
</script>

<template>
    <aside
        data-test="thread-panel"
        class="flex w-full min-w-0 shrink-0 flex-col border-l border-border md:w-96"
    >
        <header
            class="flex h-12 shrink-0 items-center gap-2 border-b border-border px-4"
        >
            <div class="min-w-0 flex-1">
                <h2 class="text-[14px] font-semibold text-foreground">
                    Thread
                </h2>
                <p
                    v-if="replyCount > 0"
                    data-test="thread-reply-count"
                    class="text-[11.5px] text-muted-foreground"
                >
                    {{ replyCount }}
                    {{ replyCount === 1 ? 'reply' : 'replies' }}
                </p>
            </div>
            <button
                type="button"
                data-test="thread-close"
                aria-label="Close thread"
                class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                @click="emit('close')"
            >
                <X class="size-4" />
            </button>
        </header>

        <div ref="scrollContainer" class="min-h-0 flex-1 overflow-y-auto">
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
                 threads mounts a fresh, bottom-anchored list. `preserve-url`
                 keeps the cursor out of the URL, matching the main timeline. -->
            <InfiniteScroll
                v-else
                :key="props.rootId"
                data="threadReplies"
                reverse
                preserve-url
            >
                <MessageList
                    :messages="props.messages"
                    :pending-uuids="props.pendingUuids"
                    :current-user-id="props.currentUserId"
                    :can-moderate="props.canModerate"
                    :online-ids="props.onlineIds"
                    in-thread
                    @edit="(message, body) => emit('edit', message, body)"
                    @delete="(message) => emit('delete', message)"
                    @forward="(message) => emit('forward', message)"
                    @jump="(id) => emit('jump', id)"
                />
            </InfiniteScroll>
        </div>

        <MessageComposer
            v-if="hasRoot && !props.readOnly"
            :key="root?.id"
            :channel-name="props.channelName"
            :members="props.members"
            placeholder="Reply…"
            allow-send-to-channel
            autofocus
            @send="
                (body, mentions, sendToChannel) =>
                    emit('send', body, mentions, sendToChannel)
            "
            @typing="emit('typing')"
        />
    </aside>
</template>
