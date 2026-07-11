<script setup lang="ts">
import { Head, InfiniteScroll, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    index,
    show,
} from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { getInitials } from '@/composables/useInitials';
import { formatDateTime } from '@/lib/datetime';
import { renderMessageBody } from '@/lib/messageBody';
import type { Message, ThreadInboxItem, ThreadInboxPage } from '@/types';

interface TeamData {
    id: string;
    name: string;
    slug: string;
}

const props = defineProps<{
    team: TeamData;
    threads: ThreadInboxPage;
}>();

const MAX_THREAD_AVATARS = 3;

const items = computed<ThreadInboxItem[]>(() => props.threads?.data ?? []);

function threadAvatars(root: Message) {
    return root.threadParticipants.slice(0, MAX_THREAD_AVATARS);
}

function extraParticipants(root: Message): number {
    return Math.max(0, root.threadParticipants.length - MAX_THREAD_AVATARS);
}

function jumpHref(item: ThreadInboxItem): string {
    return show(
        { team: props.team.slug, channel: item.channelSlug },
        { query: { thread: item.root.id } },
    ).url;
}

const page = usePage();

const viewerTimeZone = computed(
    () => page.props.auth.user.timezone ?? undefined,
);

function formatTimestamp(iso: string): string {
    return formatDateTime(iso, viewerTimeZone.value);
}
</script>

<template>
    <Head :title="$t('Threads')" />

    <header
        class="flex h-12 shrink-0 items-center gap-2.5 border-b border-border px-5"
    >
        <SidebarTrigger
            class="-ml-1.5 size-8 text-muted-foreground md:hidden"
        />
        <h1 class="text-[15px] font-semibold text-foreground">
            {{ $t('Threads') }}
        </h1>
        <Link
            :href="index(props.team.slug).url"
            class="ml-auto text-[13px] text-muted-foreground hover:text-foreground"
            >{{ $t('Back') }}</Link
        >
    </header>

    <div class="flex flex-1 justify-center overflow-y-auto px-6 py-6">
        <div class="w-full max-w-[680px]">
            <p
                v-if="items.length === 0"
                data-test="threads-empty"
                class="pt-16 text-center text-sm text-muted-foreground"
            >
                {{
                    $t(
                        "You're not following any threads yet. Reply to a thread or get @mentioned in one and it'll show up here.",
                    )
                }}
            </p>

            <InfiniteScroll v-else data="threads" preserve-url>
                <ul>
                    <li v-for="item in items" :key="item.root.id">
                        <Link
                            :href="jumpHref(item)"
                            data-test="thread-inbox-item"
                            class="flex gap-3 rounded-md border-b border-border/60 px-1 py-3 last:border-0 hover:bg-accent/40"
                        >
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-primary/10 text-[12px] font-semibold text-primary select-none"
                                aria-hidden="true"
                            >
                                {{ getInitials(item.root.user.name) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex items-baseline gap-2 text-[13px]"
                                >
                                    <span class="font-semibold text-foreground">
                                        {{ item.root.user.name }}
                                    </span>
                                    <span class="text-muted-foreground/70">
                                        <span class="text-muted-foreground/50"
                                            >#</span
                                        >{{ item.channelName }}
                                    </span>
                                    <span
                                        v-if="item.root.threadLastReplyAt"
                                        class="ml-auto shrink-0 text-[11px] text-muted-foreground/70"
                                    >
                                        {{
                                            formatTimestamp(
                                                item.root.threadLastReplyAt,
                                            )
                                        }}
                                    </span>
                                </div>
                                <p
                                    v-if="!item.root.isDeleted"
                                    class="mt-0.5 line-clamp-2 text-[14px] leading-[1.5] break-words text-foreground/90"
                                >
                                    <span
                                        v-html="
                                            renderMessageBody(
                                                item.root.body,
                                                item.root.mentions,
                                            )
                                        "
                                    ></span>
                                </p>

                                <div
                                    class="mt-1.5 flex items-center gap-2 text-[12px]"
                                >
                                    <span class="flex -space-x-1">
                                        <span
                                            v-for="participant in threadAvatars(
                                                item.root,
                                            )"
                                            :key="participant.id"
                                            class="flex size-5 items-center justify-center rounded-[6px] bg-primary/10 text-[9px] font-semibold text-primary ring-2 ring-background select-none"
                                            aria-hidden="true"
                                        >
                                            {{ getInitials(participant.name) }}
                                        </span>
                                        <span
                                            v-if="
                                                extraParticipants(item.root) > 0
                                            "
                                            class="flex size-5 items-center justify-center rounded-[6px] bg-muted text-[9px] font-semibold text-muted-foreground ring-2 ring-background select-none"
                                            aria-hidden="true"
                                        >
                                            +{{ extraParticipants(item.root) }}
                                        </span>
                                    </span>
                                    <span
                                        v-if="item.root.threadUnread"
                                        data-test="thread-unread-dot"
                                        :aria-label="$t('Unread replies')"
                                        class="size-2 shrink-0 rounded-full bg-rose-500"
                                    ></span>
                                    <span class="font-semibold text-primary">
                                        {{ item.root.threadReplyCount }}
                                        {{
                                            item.root.threadReplyCount === 1
                                                ? $t('reply')
                                                : $t('replies')
                                        }}
                                    </span>
                                </div>
                            </div>
                        </Link>
                    </li>
                </ul>
            </InfiniteScroll>
        </div>
    </div>
</template>
