<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { AlarmClock, Search } from '@lucide/vue';
import { ListboxFilter } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { show } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import {
    index as searchPage,
    suggest as suggestMessages,
} from '@/actions/App/Http/Controllers/Channels/SearchController';
import PresenceDot from '@/components/PresenceDot.vue';
import SafeHtml from '@/components/SafeHtml.vue';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandGroup,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    matchRange,
    rankChannels,
    rankChannelsByActivity,
} from '@/composables/quickSwitcher';
import { useCustomEmojis } from '@/composables/useCustomEmojis';
import { getInitials } from '@/composables/useInitials';
import { useIsMobile } from '@/composables/useIsMobile';
import { useMessageSearch } from '@/composables/useMessageSearch';
import { useOpenDirectMessage } from '@/composables/useOpenDirectMessage';
import { useTranslations } from '@/composables/useTranslations';
import { useUserGroups } from '@/composables/useUserGroups';
import { formatDateTime } from '@/lib/datetime';
import { renderMessageBody } from '@/lib/messageBody';
import { rankPeople } from '@/lib/peopleDirectory';
import { presenceLabelKey } from '@/lib/presence';
import type { RenderedPresence } from '@/lib/presence';
import { filtersToParams, parseSearchQuery } from '@/lib/searchTokens';
import type { SearchParams } from '@/lib/searchTokens';
import type { MessageSearchResult } from '@/types';
import type { Channel } from '@/types/channels';
import type { PersonRef } from '@/types/people';

const props = defineProps<{
    channels: Channel[];
    members: PersonRef[];
    currentUserId: string;
    teamSlug: string;
    /**
     * How each team member reads on the presence roster, driving the mobile
     * overlay's avatar dots and presence words.
     */
    presenceFor: (userId: string) => RenderedPresence;
    /** Whether each member is in do-not-disturb, driving the crescent badge. */
    isDndFor?: (userId: string) => boolean;
}>();

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
    /** The viewer picked the "Reminders" action; the layout owns the dialog. */
    openReminders: [];
}>();

/**
 * Our own query drives the fuzzy channel ranking. The Command's internal filter
 * state is deliberately left untouched (empty) so it never hides a subsequence
 * match that `rankChannels` chose to surface — the ranked list is the single
 * source of truth for what shows and in what order.
 */
const query = ref('');

const trimmedQuery = computed(() => query.value.trim().replace(/^#+/, ''));

/**
 * Parse the raw input into the shared filter model so `from:` / `in:` /
 * `before:` / `after:` tokens drive the same structured search the page uses —
 * the palette just has no visible chip bar. The residual text is the query the
 * results highlight and the channel/people groups keep ranking on.
 */
const parsedFilters = computed(() =>
    parseSearchQuery(query.value, {
        members: props.members,
        channels: props.channels,
    }),
);

const searchText = computed(() => parsedFilters.value.text);

/**
 * Below the breakpoint the overlay is entered without a keyboard and often
 * without a destination in mind, so recency does the ranking work: an empty
 * query reads as "recents" and score ties fall to the busiest channel. The
 * desktop palette keeps its alphabetical ordering untouched.
 */
const isMobile = useIsMobile();

const channelResults = computed(() =>
    isMobile.value
        ? rankChannelsByActivity(props.channels, query.value)
        : rankChannels(props.channels, query.value),
);

/** A run of a result's name, marked when it is the part the query matched. */
type NameSegment = { text: string; highlighted: boolean };

/**
 * Split a name around the typed query's contiguous match so the mobile rows
 * can brighten it; a single unhighlighted run when nothing contiguous matches.
 */
function nameSegments(name: string): NameSegment[] {
    const range = matchRange(name, query.value);

    if (range === null) {
        return [{ text: name, highlighted: false }];
    }

    return [
        { text: name.slice(0, range.start), highlighted: false },
        {
            text: name.slice(range.start, range.start + range.length),
            highlighted: true,
        },
        { text: name.slice(range.start + range.length), highlighted: false },
    ].filter((segment) => segment.text !== '');
}

// Team members ranked next to channels; choosing one opens/creates their DM.
const { t } = useTranslations();
const { openDirectMessage } = useOpenDirectMessage(() => props.teamSlug);

const peopleResults = computed(() =>
    rankPeople(props.members, query.value, props.currentUserId),
);

/**
 * Live message search: a debounced JSON call to the suggest endpoint, with the
 * in-flight request cancelled whenever the query changes so late responses
 * never overwrite newer ones.
 */
const {
    results: messageResults,
    isSearching: isSearchingMessages,
    search: searchMessages,
    reset: resetMessages,
} = useMessageSearch(
    (params) =>
        suggestMessages(props.teamSlug, {
            query: JSON.parse(params) as SearchParams,
        }).url,
);

// Re-search whenever the structured filters change (a token edit counts, not
// just text), keyed on the serialized params so an identical filter set is not
// re-fetched. A query with no residual text can never match, so it clears.
watch(
    parsedFilters,
    (filters) => {
        // A query with no residual text can never match, so clear rather than
        // send an empty request (keeps the URL-builder off an empty params key).
        if (filters.text === '') {
            resetMessages();

            return;
        }

        searchMessages(JSON.stringify(filtersToParams(filters)));
    },
    { deep: true },
);

// Reset everything on dismiss so the palette always reopens blank.
watch(open, (isOpen) => {
    if (!isOpen) {
        query.value = '';
        resetMessages();
    }
});

const page = usePage();

const { map: customEmojis } = useCustomEmojis();
const { groups: userGroups } = useUserGroups();

const viewerTimeZone = computed(
    () => page.props.auth.user.timezone ?? undefined,
);

function formatTimestamp(iso: string): string {
    return formatDateTime(iso, viewerTimeZone.value);
}

function selectChannel(channel: Channel): void {
    open.value = false;
    router.visit(show({ team: props.teamSlug, channel: channel.slug }).url);
}

function selectPerson(id: string): void {
    open.value = false;
    openDirectMessage(id);
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
        searchPage(props.teamSlug, {
            query: filtersToParams(parsedFilters.value),
        }).url,
    );
}

function openReminders(): void {
    open.value = false;
    emit('openReminders');
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="overflow-hidden p-0"
            mobile="fullscreen"
            :show-close-button="!isMobile"
        >
            <DialogHeader class="sr-only">
                <DialogTitle>{{ $t('Quick switcher') }}</DialogTitle>
                <DialogDescription>{{
                    $t('Jump to a channel or search messages')
                }}</DialogDescription>
            </DialogHeader>
            <Command>
                <!-- Below the breakpoint the input is a pill with a Cancel
                     affordance beside it (m5); the desktop palette keeps its
                     plain underlined row. -->
                <div
                    v-if="isMobile"
                    class="flex shrink-0 items-center gap-2.5 border-b px-3.5 pt-3.5 pb-3"
                >
                    <div
                        class="flex h-10.5 min-w-0 flex-1 items-center gap-2 rounded-full border border-input bg-card px-3.5"
                    >
                        <Search
                            class="size-3.5 shrink-0 text-muted-foreground/70"
                        />
                        <ListboxFilter
                            v-model="query"
                            auto-focus
                            :placeholder="
                                $t('Jump to a channel or search messages…')
                            "
                            data-test="quick-switcher-input"
                            class="h-full w-full min-w-0 bg-transparent text-[15px] outline-hidden placeholder:text-muted-foreground"
                        />
                    </div>
                    <Button
                        variant="ghost"
                        type="button"
                        data-test="quick-switcher-cancel"
                        class="h-10.5 shrink-0 px-1.5 text-sm font-semibold text-muted-foreground"
                        @click="open = false"
                    >
                        {{ $t('Cancel') }}
                    </Button>
                </div>
                <div
                    v-else
                    class="flex h-12 items-center gap-2.5 border-b px-4"
                >
                    <Search class="size-4 shrink-0 text-muted-foreground/70" />
                    <ListboxFilter
                        v-model="query"
                        auto-focus
                        :placeholder="
                            $t('Jump to a channel or search messages…')
                        "
                        data-test="quick-switcher-input"
                        class="flex h-10 w-full rounded-md bg-transparent py-3 text-sm outline-hidden placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50"
                    />
                </div>
                <CommandList
                    :aria-label="$t('Quick switcher')"
                    class="max-md:max-h-none max-md:flex-1 max-md:p-1.5"
                >
                    <CommandGroup
                        v-if="trimmedQuery === ''"
                        :heading="$t('Actions')"
                    >
                        <CommandItem
                            value="action:reminders"
                            data-test="quick-switcher-reminders"
                            class="group h-9.5 gap-2 rounded-lg px-2.5 max-md:h-11.5 max-md:gap-2.5 max-md:rounded-[11px] max-md:px-3 max-md:text-[15px] md:data-[highlighted]:bg-primary md:data-[highlighted]:text-primary-foreground"
                            @select="openReminders"
                        >
                            <AlarmClock
                                class="size-4 shrink-0 text-muted-foreground/70 group-data-[highlighted]:text-brass"
                            />
                            <span class="truncate">{{ $t('Reminders') }}</span>
                            <span
                                class="ml-auto font-mono text-[11px] text-primary-foreground/70 opacity-0 group-data-[highlighted]:opacity-100 max-md:hidden"
                                aria-hidden="true"
                                >↵</span
                            >
                        </CommandItem>
                    </CommandGroup>

                    <CommandGroup
                        v-if="channelResults.length > 0"
                        :heading="$t('Channels')"
                    >
                        <CommandItem
                            v-for="channel in channelResults"
                            :key="channel.id"
                            :value="`channel:${channel.id}`"
                            data-test="quick-switcher-channel"
                            class="group h-9.5 gap-2 rounded-lg px-2.5 max-md:h-11.5 max-md:gap-2.5 max-md:rounded-[11px] max-md:px-3 md:data-[highlighted]:bg-primary md:data-[highlighted]:text-primary-foreground"
                            @select="selectChannel(channel)"
                        >
                            <span
                                class="shrink-0 font-semibold text-muted-foreground group-data-[highlighted]:text-brass max-md:font-serif max-md:text-[17px] max-md:italic"
                                aria-hidden="true"
                                >#</span
                            >
                            <span class="truncate max-md:text-[15px]">
                                <template v-if="isMobile">
                                    <template
                                        v-for="(segment, index) in nameSegments(
                                            channel.name,
                                        )"
                                        :key="index"
                                    >
                                        <span
                                            v-if="segment.highlighted"
                                            data-test="quick-switcher-match"
                                            class="rounded-[3px] bg-brass/25"
                                            >{{ segment.text }}</span
                                        >
                                        <template v-else>{{
                                            segment.text
                                        }}</template>
                                    </template>
                                </template>
                                <template v-else>{{ channel.name }}</template>
                            </span>
                            <span
                                class="ml-auto font-mono text-[11px] text-primary-foreground/70 opacity-0 group-data-[highlighted]:opacity-100 max-md:hidden"
                                aria-hidden="true"
                                >↵</span
                            >
                        </CommandItem>
                    </CommandGroup>

                    <CommandGroup
                        v-if="peopleResults.length > 0"
                        :heading="$t('People')"
                    >
                        <CommandItem
                            v-for="person in peopleResults"
                            :key="person.id"
                            :value="`person:${person.id}`"
                            data-test="quick-switcher-person"
                            class="group h-9.5 gap-2 rounded-lg px-2.5 max-md:h-11.5 max-md:gap-2.5 max-md:rounded-[11px] max-md:px-3 md:data-[highlighted]:bg-primary md:data-[highlighted]:text-primary-foreground"
                            @select="selectPerson(person.id)"
                        >
                            <span
                                v-if="isMobile"
                                class="relative size-7 shrink-0"
                                aria-hidden="true"
                            >
                                <span
                                    class="flex size-7 items-center justify-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary select-none"
                                    >{{ getInitials(person.name) }}</span
                                >
                                <PresenceDot
                                    :presence="presenceFor(person.id)"
                                    :is-dnd="isDndFor?.(person.id) ?? false"
                                    surface-class="bg-sidebar"
                                    size="28"
                                    class="ring-sidebar"
                                />
                            </span>
                            <span
                                v-else
                                class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary select-none md:group-data-[highlighted]:bg-primary-foreground/20 md:group-data-[highlighted]:text-primary-foreground"
                                aria-hidden="true"
                                >{{ getInitials(person.name) }}</span
                            >
                            <span class="truncate max-md:text-[15px]">
                                <template v-if="isMobile">
                                    <template
                                        v-for="(segment, index) in nameSegments(
                                            person.isSelf
                                                ? t('You')
                                                : person.name,
                                        )"
                                        :key="index"
                                    >
                                        <span
                                            v-if="segment.highlighted"
                                            data-test="quick-switcher-match"
                                            class="rounded-[3px] bg-brass/25"
                                            >{{ segment.text }}</span
                                        >
                                        <template v-else>{{
                                            segment.text
                                        }}</template>
                                    </template>
                                </template>
                                <template v-else>{{
                                    person.isSelf ? t('You') : person.name
                                }}</template>
                            </span>
                            <span
                                v-if="isMobile"
                                class="ml-auto shrink-0 text-[11.5px] text-muted-foreground"
                                >{{
                                    $t(presenceLabelKey(presenceFor(person.id)))
                                }}</span
                            >
                            <span
                                v-else
                                class="ml-auto font-mono text-[11px] text-primary-foreground/70 opacity-0 group-data-[highlighted]:opacity-100"
                                aria-hidden="true"
                                >↵</span
                            >
                        </CommandItem>
                    </CommandGroup>

                    <CommandGroup
                        v-if="searchText !== ''"
                        :heading="$t('Messages')"
                    >
                        <p
                            v-if="
                                isSearchingMessages &&
                                messageResults.length === 0
                            "
                            class="px-2 py-2 text-xs text-muted-foreground"
                        >
                            {{ $t('Searching…') }}
                        </p>
                        <p
                            v-else-if="messageResults.length === 0"
                            data-test="quick-switcher-no-messages"
                            class="px-2 py-2 text-xs text-muted-foreground"
                        >
                            {{
                                $t('No messages match “:query”.', {
                                    query: searchText,
                                })
                            }}
                        </p>

                        <CommandItem
                            v-for="result in messageResults"
                            :key="result.message.id"
                            :value="`message:${result.message.id}`"
                            data-test="quick-switcher-message"
                            class="items-start gap-2.5 max-md:min-h-11.5 max-md:rounded-[11px] max-md:px-3"
                            @select="selectMessage(result)"
                        >
                            <div
                                class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-md bg-primary/10 text-[10px] font-semibold text-primary select-none"
                                aria-hidden="true"
                            >
                                {{ getInitials(result.message.user.name) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex items-baseline gap-1.5 text-xs"
                                >
                                    <span
                                        class="font-semibold text-foreground"
                                        >{{ result.message.user.name }}</span
                                    >
                                    <span class="text-muted-foreground"
                                        ><span class="text-muted-foreground"
                                            >#</span
                                        >{{ result.channelName }}</span
                                    >
                                    <span
                                        class="ml-auto shrink-0 text-[10px] text-muted-foreground"
                                        >{{
                                            formatTimestamp(
                                                result.message.createdAt,
                                            )
                                        }}</span
                                    >
                                </div>
                                <p
                                    class="mt-0.5 line-clamp-1 text-[13px] text-foreground/80"
                                >
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
                            @select="seeAllResults"
                        >
                            <span class="truncate">{{
                                $t('See all results for “:query”', {
                                    query: searchText,
                                })
                            }}</span>
                            <span
                                class="ml-auto shrink-0 not-italic"
                                aria-hidden="true"
                                >&rarr;</span
                            >
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
                <!-- The design's standing explanation of the empty-query list:
                     it doubles as the overlay's ranking hint, so it stays put
                     rather than living inside the scrolling results. -->
                <p
                    v-if="isMobile"
                    class="shrink-0 px-4 pt-2.5 pb-3 text-center text-[11.5px] text-muted-foreground"
                >
                    {{
                        $t(
                            'Recent shows before you type · results ranked by activity',
                        )
                    }}
                </p>
            </Command>
        </DialogContent>
    </Dialog>
</template>
