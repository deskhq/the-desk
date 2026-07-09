<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CornerDownLeft, Hash, Search } from '@lucide/vue';
import { ListboxFilter } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { show } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import {
    index as searchPage,
    suggest as suggestMessages,
} from '@/actions/App/Http/Controllers/Channels/SearchController';
import {
    CommandDialog,
    CommandGroup,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { rankChannels } from '@/composables/quickSwitcher';
import { getInitials } from '@/composables/useInitials';
import { useMessageSearch } from '@/composables/useMessageSearch';
import { renderMessageBody } from '@/lib/messageBody';
import type { MessageSearchResult } from '@/types';
import type { Channel } from '@/types/channels';

const props = defineProps<{
    channels: Channel[];
    teamSlug: string;
}>();

const open = defineModel<boolean>('open', { default: false });

// Our own query drives the fuzzy channel ranking. The Command's internal filter
// state is deliberately left untouched (empty) so it never hides a subsequence
// match that `rankChannels` chose to surface — the ranked list is the single
// source of truth for what shows and in what order.
const query = ref('');

const trimmedQuery = computed(() => query.value.trim().replace(/^#+/, ''));

const channelResults = computed(() =>
    rankChannels(props.channels, query.value),
);

// Live message search: a debounced JSON call to the suggest endpoint, with the
// in-flight request cancelled whenever the query changes so late responses
// never overwrite newer ones.
const {
    results: messageResults,
    isSearching: isSearchingMessages,
    search: searchMessages,
    reset: resetMessages,
} = useMessageSearch(
    (term) => suggestMessages(props.teamSlug, { query: { q: term } }).url,
);

watch(trimmedQuery, (term) => {
    searchMessages(term);
});

// Reset everything on dismiss so the palette always reopens blank.
watch(open, (isOpen) => {
    if (!isOpen) {
        query.value = '';
        resetMessages();
    }
});

function formatTimestamp(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function selectChannel(channel: Channel): void {
    open.value = false;
    router.visit(show({ team: props.teamSlug, channel: channel.slug }).url);
}

function selectMessage(result: MessageSearchResult): void {
    open.value = false;
    router.visit(
        show(
            { team: props.teamSlug, channel: result.channelSlug },
            { query: { message: result.message.id } },
        ).url,
    );
}

function seeAllResults(): void {
    open.value = false;
    router.visit(
        searchPage(props.teamSlug, { query: { q: trimmedQuery.value } }).url,
    );
}
</script>

<template>
    <CommandDialog
        v-model:open="open"
        title="Quick switcher"
        description="Jump to a channel or search messages"
    >
        <div class="flex h-11 items-center gap-2 border-b px-3">
            <Search class="size-4 shrink-0 opacity-50" />
            <ListboxFilter
                v-model="query"
                auto-focus
                placeholder="Jump to a channel or search messages…"
                data-test="quick-switcher-input"
                class="flex h-10 w-full rounded-md bg-transparent py-3 text-sm outline-hidden placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50"
            />
        </div>
        <CommandList>
            <CommandGroup v-if="channelResults.length > 0" heading="Channels">
                <CommandItem
                    v-for="channel in channelResults"
                    :key="channel.id"
                    :value="`channel:${channel.id}`"
                    data-test="quick-switcher-channel"
                    class="gap-2"
                    @select="selectChannel(channel)"
                >
                    <Hash class="size-4 shrink-0 opacity-60" />
                    <span class="truncate">{{ channel.name }}</span>
                </CommandItem>
            </CommandGroup>

            <CommandGroup v-if="trimmedQuery !== ''" heading="Messages">
                <p
                    v-if="isSearchingMessages && messageResults.length === 0"
                    class="px-2 py-2 text-xs text-muted-foreground"
                >
                    Searching…
                </p>
                <p
                    v-else-if="messageResults.length === 0"
                    data-test="quick-switcher-no-messages"
                    class="px-2 py-2 text-xs text-muted-foreground"
                >
                    No messages match &ldquo;{{ trimmedQuery }}&rdquo;.
                </p>

                <CommandItem
                    v-for="result in messageResults"
                    :key="result.message.id"
                    :value="`message:${result.message.id}`"
                    data-test="quick-switcher-message"
                    class="items-start gap-2.5"
                    @select="selectMessage(result)"
                >
                    <div
                        class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-md bg-primary/10 text-[10px] font-semibold text-primary select-none"
                        aria-hidden="true"
                    >
                        {{ getInitials(result.message.user.name) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-1.5 text-xs">
                            <span class="font-medium text-foreground">{{
                                result.message.user.name
                            }}</span>
                            <span class="text-muted-foreground/70"
                                ><span class="text-muted-foreground/50">#</span
                                >{{ result.channelName }}</span
                            >
                            <span
                                class="ml-auto shrink-0 text-[10px] text-muted-foreground/60"
                                >{{
                                    formatTimestamp(result.message.createdAt)
                                }}</span
                            >
                        </div>
                        <p
                            class="mt-0.5 line-clamp-1 text-[13px] text-foreground/80"
                        >
                            <span
                                v-html="
                                    renderMessageBody(
                                        result.message.body,
                                        result.message.mentions,
                                    )
                                "
                            ></span>
                        </p>
                    </div>
                </CommandItem>

                <CommandItem
                    value="see-all-results"
                    data-test="quick-switcher-see-all"
                    class="gap-2 text-muted-foreground"
                    @select="seeAllResults"
                >
                    <CornerDownLeft class="size-4 shrink-0 opacity-60" />
                    <span class="truncate"
                        >See all results for &ldquo;{{
                            trimmedQuery
                        }}&rdquo;</span
                    >
                </CommandItem>
            </CommandGroup>
        </CommandList>
    </CommandDialog>
</template>
