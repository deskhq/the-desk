<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import { ChevronDown, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import MessageComposer from '@/components/MessageComposer.vue';
import MessageList from '@/components/MessageList.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useScrollPin } from '@/composables/useScrollPin';
import type { Mention, Message } from '@/types';

const props = defineProps<{
    // The open thread's root id, used to key the reply scroll so switching
    // threads mounts a fresh, bottom-anchored list.
    rootId: string;
    teamSlug: string;
    channelName: string;
    // The root followed by its replies (oldest first), merged and deduped by the
    // parent's thread stream; empty while the thread is still loading.
    messages: Message[];
    pendingUuids?: string[];
    members: Mention[];
    currentUserId: string;
    canModerate?: boolean;
    canReact?: boolean;
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
    react: [message: Message, emoji: string];
    remind: [message: Message, remindAt: string];
    remindCustom: [message: Message];
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

// Shared scroll/pin bookkeeping, identical to the main timeline's: the
// pinned-to-newest flag, the "N new replies" count while scrolled up, and the
// smooth jump back to the bottom.
const scrollContainer = ref<HTMLElement | null>(null);
const {
    pinnedToBottom,
    newMessageCount,
    isNearBottom,
    scrollToBottom,
    notifyAppended,
    onScroll,
} = useScrollPin(scrollContainer);

// The thread reply composer, so a hover card on a thread message can drop a
// mention into it rather than the main channel composer.
const threadComposer = ref<InstanceType<typeof MessageComposer> | null>(null);

function mentionInThread(member: { id: string; name: string }): void {
    threadComposer.value?.insertMention(member);
}

// The id of the newest reply currently shown, so a reply appended at the bottom
// (which should follow the reader or raise the count) can be told apart from
// older replies paging in at the top, which must leave the viewport anchored.
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
        class="flex w-full min-w-0 shrink-0 flex-col overflow-hidden border-l border-border md:m-3.5 md:w-96 md:rounded-[14px] md:border md:border-border md:bg-sidebar md:shadow-sm"
    >
        <header
            class="flex shrink-0 items-start gap-2 border-b border-border px-[18px] pt-4 pb-3"
        >
            <div class="min-w-0 flex-1">
                <h2
                    class="font-serif text-[19px] leading-[1.1] font-semibold text-foreground"
                >
                    {{ $t('Thread') }}
                </h2>
                <p
                    data-test="thread-reply-count"
                    class="mt-0.5 text-[11.5px] text-muted-foreground"
                >
                    <template v-if="replyCount > 0"
                        >{{
                            replyCount === 1
                                ? $t(':count reply', { count: replyCount })
                                : $t(':count replies', { count: replyCount })
                        }}
                        · </template
                    >#{{ props.channelName }}
                </p>
            </div>
            <button
                type="button"
                data-test="thread-close"
                :aria-label="$t('Close thread')"
                class="flex size-[26px] shrink-0 items-center justify-center rounded-lg border border-border text-muted-foreground hover:bg-muted hover:text-foreground"
                @click="emit('close')"
            >
                <X class="size-3.5" />
            </button>
        </header>

        <div class="relative flex min-h-0 flex-1 flex-col">
            <div
                ref="scrollContainer"
                class="scrollbar-thin min-h-0 flex-1 scrollbar-thumb-border scrollbar-track-transparent overflow-y-auto overscroll-contain"
                @scroll.passive="onScroll"
            >
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
                        :team-slug="props.teamSlug"
                        :pending-uuids="props.pendingUuids"
                        :current-user-id="props.currentUserId"
                        :can-moderate="props.canModerate"
                        :can-react="props.canReact"
                        :online-ids="props.onlineIds"
                        in-thread
                        @edit="(message, body) => emit('edit', message, body)"
                        @delete="(message) => emit('delete', message)"
                        @forward="(message) => emit('forward', message)"
                        @react="
                            (message, emoji) => emit('react', message, emoji)
                        "
                        @remind="
                            (message, remindAt) =>
                                emit('remind', message, remindAt)
                        "
                        @remind-custom="
                            (message) => emit('remindCustom', message)
                        "
                        @jump="(id) => emit('jump', id)"
                        @mention="mentionInThread"
                    />
                </InfiniteScroll>
            </div>

            <Transition
                enter-active-class="transition duration-150 ease-out"
                enter-from-class="translate-y-1 opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition duration-100 ease-in"
                leave-from-class="translate-y-0 opacity-100"
                leave-to-class="translate-y-1 opacity-0"
            >
                <button
                    v-if="!pinnedToBottom"
                    type="button"
                    data-test="jump-to-latest-thread"
                    :data-new-count="newMessageCount"
                    :aria-label="
                        newMessageCount > 0
                            ? $t(':count new replies, jump to latest', {
                                  count: newMessageCount,
                              })
                            : $t('Jump to latest reply')
                    "
                    class="absolute right-4 bottom-4 z-10 inline-flex items-center gap-1.5 rounded-full shadow-md transition-colors"
                    :class="
                        newMessageCount > 0
                            ? 'bg-primary px-3 py-1.5 text-[12px] font-semibold text-primary-foreground hover:opacity-90'
                            : 'size-9 justify-center bg-card text-muted-foreground ring-1 ring-border hover:bg-muted hover:text-foreground'
                    "
                    @click="scrollToBottom(true)"
                >
                    <ChevronDown class="size-4 shrink-0" />
                    <span v-if="newMessageCount > 0">
                        {{
                            newMessageCount === 1
                                ? $t(':count new reply', {
                                      count: newMessageCount,
                                  })
                                : $t(':count new replies', {
                                      count: newMessageCount,
                                  })
                        }}
                    </span>
                </button>
            </Transition>
        </div>

        <MessageComposer
            v-if="hasRoot && !props.readOnly"
            ref="threadComposer"
            :key="root?.id"
            :channel-name="props.channelName"
            :members="props.members"
            :placeholder="$t('Reply…')"
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
