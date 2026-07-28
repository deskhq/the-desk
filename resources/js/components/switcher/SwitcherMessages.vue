<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import SafeHtml from '@/components/SafeHtml.vue';
import { CommandGroup, CommandItem } from '@/components/ui/command';
import { useCustomEmojis } from '@/composables/useCustomEmojis';
import { getInitials } from '@/composables/useInitials';
import { useUserGroups } from '@/composables/useUserGroups';
import { formatDateTime } from '@/lib/datetime';
import { renderMessageBody } from '@/lib/messageBody';
import type { MessageSearchResult } from '@/types';

defineProps<{
    /** The matches the suggest endpoint last answered with. */
    results: MessageSearchResult[];
    /** Whether a request is outstanding, which reads as "Searching…". */
    isSearching: boolean;
    /** The residual text the matches were selected by, named back to the viewer. */
    searchText: string;
}>();

defineEmits<{
    /** The viewer picked a match; the palette opens it in its channel. */
    select: [result: MessageSearchResult];
    /** The viewer asked for the full result set, which the Search panel holds. */
    seeAll: [];
}>();

const page = usePage();

const { map: customEmojis } = useCustomEmojis();
const { groups: userGroups } = useUserGroups();

const viewerTimeZone = computed(
    () => page.props.auth.user.timezone ?? undefined,
);

function formatTimestamp(iso: string): string {
    return formatDateTime(iso, viewerTimeZone.value);
}
</script>

<template>
    <CommandGroup :heading="$t('Messages')">
        <p
            v-if="isSearching && results.length === 0"
            class="px-2 py-2 text-xs text-muted-foreground"
        >
            {{ $t('Searching…') }}
        </p>
        <p
            v-else-if="results.length === 0"
            data-test="quick-switcher-no-messages"
            class="px-2 py-2 text-xs text-muted-foreground"
        >
            {{ $t('No messages match “:query”.', { query: searchText }) }}
        </p>

        <CommandItem
            v-for="result in results"
            :key="result.message.id"
            :value="`message:${result.message.id}`"
            data-test="quick-switcher-message"
            class="items-start gap-2.5 max-md:min-h-11.5 max-md:rounded-[11px] max-md:px-3"
            @select="$emit('select', result)"
        >
            <div
                class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-md bg-primary/10 text-[10px] font-semibold text-primary select-none"
                aria-hidden="true"
            >
                {{ getInitials(result.message.user.name) }}
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-baseline gap-1.5 text-xs">
                    <span class="font-semibold text-foreground">{{
                        result.message.user.name
                    }}</span>
                    <span class="text-muted-foreground"
                        ><span
                            v-if="!result.isDirectMessage"
                            class="text-muted-foreground"
                            >#</span
                        >{{ result.channelName }}</span
                    >
                    <span
                        class="ml-auto shrink-0 text-[10px] text-muted-foreground"
                        >{{ formatTimestamp(result.message.createdAt) }}</span
                    >
                </div>
                <p class="mt-0.5 line-clamp-1 text-[13px] text-foreground/80">
                    <SafeHtml
                        :html="
                            renderMessageBody(
                                result.message.body,
                                result.message.mentions,
                                customEmojis,
                                userGroups,
                            )
                        "
                        variant="messageBody"
                    />
                </p>
            </div>
        </CommandItem>

        <CommandItem
            value="see-all-results"
            data-test="quick-switcher-see-all"
            class="mt-1 gap-2 rounded-lg border-t border-border px-2.5 font-serif text-[13px] text-muted-foreground italic data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground max-md:min-h-11.5 max-md:rounded-[11px] max-md:px-3"
            @select="$emit('seeAll')"
        >
            <span class="truncate">{{
                $t('See all results for “:query”', { query: searchText })
            }}</span>
            <span class="ml-auto shrink-0 not-italic" aria-hidden="true"
                >&rarr;</span
            >
        </CommandItem>
    </CommandGroup>
</template>
