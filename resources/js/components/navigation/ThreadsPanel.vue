<script setup lang="ts">
import { InfiniteScroll, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { show } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { markAllRead } from '@/actions/App/Http/Controllers/Channels/ThreadsController';
import AvatarStack from '@/components/AvatarStack.vue';
import DestinationPanel from '@/components/navigation/DestinationPanel.vue';
import SafeHtml from '@/components/SafeHtml.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useCustomEmojis } from '@/composables/useCustomEmojis';
import { useInitials } from '@/composables/useInitials';
import { urlForDestination } from '@/composables/useNavPanel';
import { useTranslations } from '@/composables/useTranslations';
import { useUserGroups } from '@/composables/useUserGroups';
import { formatCompactTimestamp } from '@/lib/datetime';
import { renderMessageBody } from '@/lib/messageBody';
import {
    filterFromUrl,
    THREAD_INBOX_FILTERS,
    urlForThreadInboxFilter,
} from '@/lib/threadInbox';
import type { ThreadInboxFilter } from '@/lib/threadInbox';
import type { ThreadInboxItem } from '@/types';

/**
 * The Threads destination: every thread the viewer follows in this workspace,
 * unread first-class, inside the dock's 300px panel while the channel behind it
 * stays live.
 *
 * The filter is a **server** query param, not a client-side sieve: the inbox is
 * cursor-paginated, so filtering the loaded page here would report "unread" from
 * whatever happened to fit on screen. Switching a pill is therefore one partial
 * visit that both pins the filter on the URL — making the panel shareable and
 * reload-proof, like `?nav=` itself — and re-fetches the prop, resetting the
 * merged pages so the new filter starts from its own first page.
 */
const page = usePage();

const { t } = useTranslations();
const { getInitials } = useInitials();
const { map: customEmojis } = useCustomEmojis();
const { groups: userGroups } = useUserGroups();

/** How many participant avatars a card stacks before collapsing into "+N". */
const MAX_PARTICIPANT_AVATARS = 3;

/** The skeleton cards shown while the inbox is in flight. */
const SKELETON_CARDS = [0, 1, 2, 3];

const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');
const activeFilter = computed<ThreadInboxFilter>(() => filterFromUrl(page.url));
const inbox = computed(() => page.props.threads);
const items = computed<ThreadInboxItem[]>(() => inbox.value?.data ?? []);
const unreadCount = computed(() => page.props.unreadThreadCount ?? 0);
const viewerTimeZone = computed(
    () => page.props.auth.user.timezone ?? undefined,
);

/** Cancels the inbox request currently in flight, if there is one. */
let cancelLoad: (() => void) | null = null;

/**
 * Pull the inbox for a filter, replacing rather than appending the pages already
 * merged in.
 *
 * The destination is pinned onto the URL here as well as by the rail, because the
 * props ride on `?nav=threads`: the rail's own `router.replace` is a client-side
 * visit whose history write lands a tick later, so a request built from `page.url`
 * alone could still be asking as the conversation list (#947).
 */
function load(filter: ThreadInboxFilter): void {
    router.get(
        urlForThreadInboxFilter(urlForDestination(page.url, 'threads'), filter),
        {},
        {
            only: ['threads', 'unreadThreadCount'],
            reset: ['threads'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onCancelToken: (token) => {
                cancelLoad = token.cancel;
            },
            onFinish: () => {
                cancelLoad = null;
            },
        },
    );
}

// A panel the viewer has already left must not be allowed to finish: this visit
// pins `?nav=threads` on the URL, and landing after a switch back to the
// conversation list would drag the destination param back with it.
onBeforeUnmount(() => {
    cancelLoad?.();
});

// Opening the destination is a client-side visit, so the panel arrives without
// its props and fetches them itself. A deep link (`?nav=threads`) already carries
// them, and refetching would only throw the first page away and load it again.
onMounted(() => {
    if (inbox.value === undefined) {
        load(activeFilter.value);
    }
});

function selectFilter(filter: ThreadInboxFilter): void {
    if (filter !== activeFilter.value) {
        load(filter);
    }
}

/**
 * Clear the whole inbox. The redirect back re-renders this route, so the emptied
 * cards, the zeroed tally and the dropped rail dot all arrive as fresh props.
 */
function clearInbox(): void {
    router.post(
        markAllRead({ team: teamSlug.value }).url,
        {},
        {
            preserveScroll: true,
        },
    );
}

/** Where a card jumps to: its channel, with the thread panel already open. */
function jumpHref(item: ThreadInboxItem): string {
    return show(
        { team: teamSlug.value, channel: item.channelSlug },
        { query: { thread: item.root.id } },
    ).url;
}

function isUnread(item: ThreadInboxItem): boolean {
    return item.root.threadUnread;
}

/**
 * The reply line: what is new to the viewer on an unread thread, the thread's
 * whole length once they are caught up.
 */
function replyLabel(item: ThreadInboxItem): string {
    if (isUnread(item)) {
        const count = item.root.threadUnreadReplyCount;

        return count === 1
            ? t(':count new reply', { count })
            : t(':count new replies', { count });
    }

    const count = item.root.threadReplyCount;

    return count === 1
        ? t(':count reply', { count })
        : t(':count replies', { count });
}
</script>

<template>
    <DestinationPanel destination="threads" body-class="px-2.5 py-1">
        <template #action>
            <!-- Only offered when it has something to do. A disabled control here
                 would be a dimmed 11.5px label on the panel's own surface, which
                 cannot clear the contrast bar in the light theme (#269). -->
            <Button
                v-if="unreadCount > 0"
                variant="link"
                size="none"
                data-test="threads-mark-all-read"
                class="shrink-0 text-[11.5px] font-normal text-sidebar-foreground no-underline hover:underline"
                @click="clearInbox"
            >
                {{ $t('Mark all read') }}
            </Button>
        </template>

        <template #toolbar>
            <div
                role="group"
                :aria-label="$t('Filter threads')"
                class="flex shrink-0 gap-1.5 px-2.5 pt-2.5 pb-1.5"
            >
                <Button
                    v-for="filter in THREAD_INBOX_FILTERS"
                    :key="filter"
                    variant="segmented"
                    size="pill"
                    :data-test="`threads-filter-${filter}`"
                    :aria-pressed="activeFilter === filter"
                    class="h-6.5 border border-transparent px-2.75 text-[12px] font-semibold text-sidebar-foreground aria-pressed:bg-sidebar-foreground aria-pressed:text-sidebar aria-[pressed=false]:border-sidebar-border"
                    @click="selectFilter(filter)"
                >
                    <template v-if="filter === 'unread'">
                        {{ $t('Unread') }}
                        <span
                            v-if="unreadCount > 0"
                            data-test="threads-unread-count"
                            class="font-semibold opacity-70"
                            >{{ unreadCount }}</span
                        >
                    </template>
                    <template v-else>{{ $t('All') }}</template>
                </Button>
            </div>
        </template>

        <!-- The inbox arrives on its own visit, so the panel opens on a pulsing
             stand-in of the cards rather than an empty column. -->
        <div v-if="inbox === undefined" class="flex flex-col gap-1.5">
            <div
                v-for="card in SKELETON_CARDS"
                :key="card"
                data-test="threads-skeleton-card"
                class="flex animate-pulse flex-col gap-2 rounded-xl border border-sidebar-border p-2.75"
                aria-hidden="true"
            >
                <span class="h-2 w-2/5 rounded-full bg-sidebar-accent" />
                <span class="h-2 w-full rounded-full bg-sidebar-accent" />
                <span class="h-2 w-3/5 rounded-full bg-sidebar-accent" />
            </div>
        </div>

        <p
            v-else-if="items.length === 0"
            data-test="threads-empty"
            class="px-1 pt-10 text-center text-[12.5px] leading-relaxed text-sidebar-foreground/70"
        >
            {{
                activeFilter === 'unread'
                    ? $t("You're all caught up. Nothing new in your threads.")
                    : $t(
                          "You're not following any threads yet. Reply to a thread or get @mentioned in one and it'll show up here.",
                      )
            }}
        </p>

        <InfiniteScroll v-else data="threads" preserve-url>
            <ul class="flex flex-col gap-1.5">
                <li v-for="item in items" :key="item.root.id">
                    <Link
                        :href="jumpHref(item)"
                        data-test="thread-inbox-item"
                        class="flex flex-col gap-1.75 rounded-xl p-2.75 transition-colors"
                        :class="
                            isUnread(item)
                                ? 'border-l-2 border-brass bg-sidebar-accent hover:bg-sidebar-accent/80'
                                : 'border border-sidebar-border hover:bg-sidebar-accent/50'
                        "
                    >
                        <span
                            class="flex items-center gap-1.5 text-[11px] text-sidebar-foreground/70"
                        >
                            <Avatar
                                v-if="item.dmParticipant"
                                class="size-3.5 shrink-0 rounded-full"
                            >
                                <AvatarImage
                                    v-if="item.dmParticipant.avatar"
                                    :src="item.dmParticipant.avatar"
                                    :alt="''"
                                />
                                <AvatarFallback
                                    class="rounded-full bg-brass/25 text-[6.5px] font-semibold text-sidebar-foreground"
                                >
                                    {{ getInitials(item.dmParticipant.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <span
                                v-else-if="!item.isDirectMessage"
                                aria-hidden="true"
                                >#</span
                            >
                            <span class="truncate">{{ item.channelName }}</span>
                            <span
                                v-if="item.root.threadLastReplyAt"
                                class="ml-auto shrink-0 tabular-nums"
                                >{{
                                    formatCompactTimestamp(
                                        item.root.threadLastReplyAt,
                                        viewerTimeZone,
                                    )
                                }}</span
                            >
                        </span>

                        <p
                            v-if="!item.root.isDeleted"
                            class="line-clamp-2 text-[12.5px] leading-[1.45] break-words"
                            :class="
                                isUnread(item)
                                    ? 'text-sidebar-foreground'
                                    : 'text-sidebar-foreground/70'
                            "
                        >
                            <SafeHtml
                                :html="
                                    renderMessageBody(
                                        item.root.body,
                                        item.root.mentions,
                                        customEmojis,
                                        userGroups,
                                    )
                                "
                                variant="messageBody"
                            />
                        </p>

                        <span class="flex items-center gap-1.75">
                            <AvatarStack
                                v-if="
                                    isUnread(item) &&
                                    item.root.threadParticipants.length > 0
                                "
                                :members="item.root.threadParticipants"
                                :max="MAX_PARTICIPANT_AVATARS"
                                size="sm"
                                ring-class="ring-sidebar-accent"
                                surface-class="bg-sidebar-accent"
                            />
                            <!-- The unread cue the old full-width row carried as a
                                 dot: the design replaces it with this brass line,
                                 plus the card's border and fill. -->
                            <span
                                v-if="isUnread(item)"
                                data-test="thread-unread-dot"
                                class="text-[11px] font-semibold text-brass-fill-foreground"
                                >{{ replyLabel(item) }}</span
                            >
                            <span
                                v-else
                                class="text-[11px] text-sidebar-foreground/70"
                                >{{ replyLabel(item) }}</span
                            >
                        </span>
                    </Link>
                </li>
            </ul>
        </InfiniteScroll>
    </DestinationPanel>
</template>
