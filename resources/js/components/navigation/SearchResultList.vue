<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { show } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import SafeHtml from '@/components/SafeHtml.vue';
import { getInitials } from '@/composables/useInitials';
import { formatCompactTimestamp } from '@/lib/datetime';
import { groupSearchResults } from '@/lib/searchResultGroups';
import type { MessageSearchResult } from '@/types';

/**
 * The Search panel's matches, bucketed by recency: an author avatar and name, the
 * time and the channel or DM the message lives in, and the highlighted snippet
 * beneath.
 *
 * The stamps are compact ("13:48", "Mon", "3 Jul") because the group header
 * above them already carries the day — in a 300px column a full date beside the
 * channel name would push one of the two out of the row.
 */
const props = defineProps<{
    results: MessageSearchResult[];
    /** The workspace the dock is in, so only foreign results get tagged. */
    currentTeamSlug: string;
    /** The viewer's zone, so a stamp is read in the day they are living in. */
    timeZone: string | undefined;
}>();

const groups = computed(() => groupSearchResults(props.results));

/**
 * Each result links into its own team's channel — for a same-team result that is
 * the current team; for a cross-team ("All workspaces") result it targets the
 * message's own workspace, which resolves because ACL guarantees membership.
 */
/**
 * The conversation a match came from: its channel, hashed, or — for a direct
 * message, which has no name of its own — the person (or people) on the other
 * side of it, as the DTO names them.
 */
function conversationLabel(result: MessageSearchResult): string {
    return result.isDirectMessage
        ? result.channelName
        : `#${result.channelName}`;
}

function jumpHref(result: MessageSearchResult): string {
    return show(
        { team: result.teamSlug, channel: result.channelSlug },
        { query: { message: result.message.id } },
    ).url;
}
</script>

<template>
    <div class="flex flex-col">
        <template v-for="group in groups" :key="group.key">
            <h3
                class="px-1 pt-2.5 pb-1 text-[10.5px] font-semibold tracking-[0.1em] text-sidebar-foreground/70 uppercase max-md:text-[11px]"
            >
                {{ group.label }}
            </h3>
            <Link
                v-for="result in group.results"
                :key="result.message.id"
                :href="jumpHref(result)"
                data-test="search-result"
                class="flex gap-2.25 rounded-[11px] p-2.5 transition-colors hover:bg-sidebar-accent max-md:gap-3 max-md:rounded-[14px] max-md:p-3.25"
            >
                <span
                    class="flex size-5.5 shrink-0 items-center justify-center rounded-full bg-sidebar-accent text-[8.5px] font-semibold text-sidebar-foreground/70 select-none max-md:size-7.5 max-md:text-[11px]"
                    aria-hidden="true"
                >
                    {{ getInitials(result.message.user.name) }}
                </span>
                <span
                    class="flex min-w-0 flex-1 flex-col gap-0.75 max-md:gap-1"
                >
                    <span class="flex items-baseline gap-1.5">
                        <span
                            class="truncate text-[12.5px] font-semibold text-sidebar-foreground max-md:text-[15px]"
                        >
                            {{ result.message.user.name }}
                        </span>
                        <span
                            class="shrink-0 text-[10.5px] text-sidebar-foreground/70 max-md:text-[12px]"
                        >
                            {{
                                formatCompactTimestamp(
                                    result.message.createdAt,
                                    props.timeZone,
                                )
                            }}
                        </span>
                        <span
                            class="min-w-0 truncate text-[10.5px] text-sidebar-foreground/70 max-md:text-[12px]"
                        >
                            · {{ conversationLabel(result) }}
                        </span>
                    </span>
                    <span
                        v-if="result.teamSlug !== props.currentTeamSlug"
                        class="inline-flex w-fit items-center rounded bg-brass-fill px-1.25 py-0.25 text-[9.5px] font-semibold tracking-[0.05em] text-brass-fill-foreground uppercase"
                        data-test="result-workspace-tag"
                    >
                        {{ result.teamName }}
                    </span>
                    <SafeHtml
                        as="span"
                        class="search-snippet line-clamp-2 text-[12px] leading-[1.45] break-words text-sidebar-foreground/80 max-md:text-[14px]"
                        :html="result.snippet"
                        variant="searchSnippet"
                    />
                </span>
            </Link>
        </template>
    </div>
</template>

<style scoped>
/*
 * Server-built `<mark>` highlights are rendered through `<SafeHtml>`; style them
 * with the brass reaction-pill tokens, which carry an AA-contrast foreground in
 * both light and dark themes.
 */
.search-snippet :deep(mark) {
    background-color: var(--brass-fill);
    color: var(--brass-fill-foreground);
    border-radius: 3px;
    padding: 0 2px;
    font-weight: 600;
}
</style>
