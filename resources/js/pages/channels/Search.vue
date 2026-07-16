<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Calendar,
    ChevronDown,
    Lock,
    MessagesSquare,
    Search,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    index,
    show,
} from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { index as search } from '@/actions/App/Http/Controllers/Channels/SearchController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useDebouncedPost } from '@/composables/useDebouncedPost';
import { getInitials } from '@/composables/useInitials';
import { useTranslations } from '@/composables/useTranslations';
import { formatCalendarDate, formatDateTime } from '@/lib/datetime';
import { groupSearchResults } from '@/lib/searchResultGroups';
import {
    emptyFilters,
    filtersToParams,
    parseSearchQuery,
    type SearchFilters,
} from '@/lib/searchTokens';
import type { MessageSearchResult } from '@/types';

interface TeamData {
    id: string;
    name: string;
    slug: string;
}

interface AppliedFilters {
    from: string | null;
    in: string | null;
    after: string | null;
    before: string | null;
    scope: string;
}

const props = defineProps<{
    team: TeamData;
    query: string;
    filters: AppliedFilters;
    results: MessageSearchResult[];
}>();

const { t } = useTranslations();
const page = usePage();

// The current team's channels and members feed the facet pickers and the token
// parser; both are shared props, so the search route already carries them.
const channels = computed(() => page.props.channels ?? []);
const members = computed(() => page.props.teamMembers ?? []);
const lookup = computed(() => ({
    members: members.value,
    channels: channels.value,
}));

const memberById = computed(
    () => new Map(members.value.map((member) => [member.id, member])),
);
const channelById = computed(
    () => new Map(channels.value.map((channel) => [channel.id, channel])),
);

// The URL is the state: the input and facets seed from the server-echoed query
// and filters, and every change writes them back so a shared link reproduces the
// filtered view.
const term = ref(props.query);
const authorId = ref<string | null>(props.filters.from);
const channelId = ref<string | null>(props.filters.in);
const after = ref<string | null>(props.filters.after);
const before = ref<string | null>(props.filters.before);

function currentFilters(): SearchFilters {
    return {
        ...emptyFilters(),
        text: term.value.trim(),
        from: authorId.value,
        in: channelId.value,
        after: after.value,
        before: before.value,
    };
}

// A single scoped reload that refreshes only the results and the echoed
// query/filters, preserving the input's focus and the scroll position.
function reload(): void {
    router.get(search(props.team.slug).url, filtersToParams(currentFilters()), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['results', 'query', 'filters'],
    });
}

// Debounce keystrokes; a facet change (chip/picker) reloads immediately.
const debouncedTerm = useDebouncedPost((raw: string) => commitTerm(raw), {
    delay: 300,
});
let skipNextSchedule = false;

watch(term, (value) => {
    if (skipNextSchedule) {
        skipNextSchedule = false;

        return;
    }

    debouncedTerm.schedule(value);
});

// Recognized `from:` / `in:` / `before:` / `after:` tokens promote to facet
// chips and are stripped from the visible input; unknown tokens stay literal.
function commitTerm(raw: string): void {
    const parsed = parseSearchQuery(raw, lookup.value);

    if (parsed.from !== null) {
        authorId.value = parsed.from;
    }
    if (parsed.in !== null) {
        channelId.value = parsed.in;
    }
    if (parsed.after !== null) {
        after.value = parsed.after;
    }
    if (parsed.before !== null) {
        before.value = parsed.before;
    }

    if (parsed.text !== raw.trim()) {
        skipNextSchedule = true;
        term.value = parsed.text;
    }

    reload();
}

function setAuthor(id: string): void {
    authorId.value = id;
    reload();
}

function clearAuthor(): void {
    authorId.value = null;
    reload();
}

function setChannel(id: string): void {
    channelId.value = id;
    reload();
}

function clearChannel(): void {
    channelId.value = null;
    reload();
}

function setDateRange(from: string | null, to: string | null): void {
    after.value = from;
    before.value = to;
    reload();
}

function clearDate(): void {
    setDateRange(null, null);
}

function clearAllFilters(): void {
    authorId.value = null;
    channelId.value = null;
    after.value = null;
    before.value = null;
    reload();
}

function searchAllChannels(): void {
    channelId.value = null;
    reload();
}

// Facet-picker filter inputs.
const channelFilter = ref('');
const authorFilter = ref('');

const filteredChannels = computed(() => {
    const needle = channelFilter.value.trim().toLowerCase();

    return channels.value.filter(
        (channel) =>
            needle === '' || channel.name.toLowerCase().includes(needle),
    );
});

const filteredMembers = computed(() => {
    const needle = authorFilter.value.trim().toLowerCase();

    return members.value.filter(
        (member) => needle === '' || member.name.toLowerCase().includes(needle),
    );
});

// Date presets set the same before/after bounds a custom range would; the chip
// then renders from the bounds, so presets need no remembered identity.
function isoDay(date: Date): string {
    const pad = (value: number): string => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function daysAgo(count: number): string {
    const date = new Date();
    date.setDate(date.getDate() - count);

    return isoDay(date);
}

const datePresets = computed(() => {
    const today = isoDay(new Date());

    return [
        { key: 'today', label: t('Today'), after: today, before: today },
        { key: '7d', label: t('Last 7 days'), after: daysAgo(7), before: null },
        {
            key: '30d',
            label: t('Last 30 days'),
            after: daysAgo(30),
            before: null,
        },
        {
            key: 'year',
            label: t('This year'),
            after: `${new Date().getFullYear()}-01-01`,
            before: null,
        },
    ];
});

const showCustomRange = ref(after.value !== null || before.value !== null);

// The applied chip labels.
const authorName = computed(
    () =>
        (authorId.value && memberById.value.get(authorId.value)?.name) || null,
);
const channelName = computed(
    () =>
        (channelId.value && channelById.value.get(channelId.value)?.name) ||
        null,
);
const dateChipLabel = computed(() => {
    if (after.value !== null && before.value !== null) {
        return `${formatCalendarDate(after.value)} – ${formatCalendarDate(before.value)}`;
    }
    if (after.value !== null) {
        return t('Since :date', { date: formatCalendarDate(after.value) });
    }
    if (before.value !== null) {
        return t('Before :date', { date: formatCalendarDate(before.value) });
    }

    return null;
});

const hasFilters = computed(
    () =>
        authorId.value !== null ||
        channelId.value !== null ||
        after.value !== null ||
        before.value !== null,
);

// The zero-result summary names the active filters back to the user.
const activeFilterSummary = computed(() => {
    const parts: string[] = [];

    if (channelName.value !== null) {
        parts.push(`#${channelName.value}`);
    }
    if (authorName.value !== null) {
        parts.push(t('from :name', { name: authorName.value }));
    }
    if (dateChipLabel.value !== null) {
        parts.push(dateChipLabel.value);
    }

    return parts.join(', ');
});

const groups = computed(() => groupSearchResults(props.results));

const resultCount = computed(() => props.results.length);

function formatTimestamp(iso: string): string {
    return formatDateTime(iso, page.props.auth.user.timezone ?? undefined);
}

function jumpHref(result: MessageSearchResult): string {
    return show(
        { team: props.team.slug, channel: result.channelSlug },
        { query: { message: result.message.id } },
    ).url;
}
</script>

<template>
    <Head :title="$t('Search messages')" />

    <header
        class="flex h-12 shrink-0 items-center gap-2.5 border-b border-border px-5"
    >
        <SidebarTrigger class="-ml-1.5 size-8 text-muted-foreground md:hidden" />
        <h1 class="text-[15px] font-semibold text-foreground">
            {{ $t('Search messages') }}
        </h1>
        <Link
            :href="index(props.team.slug).url"
            class="ml-auto text-[13px] text-muted-foreground hover:text-foreground"
            >{{ $t('Back') }}</Link
        >
    </header>

    <div class="flex flex-1 justify-center overflow-y-auto px-6 pt-8">
        <div class="flex w-full max-w-[660px] flex-col gap-3">
            <!-- input -->
            <div class="relative">
                <Search
                    class="absolute top-1/2 left-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="term"
                    type="search"
                    :placeholder="$t('Search messages')"
                    :aria-label="$t('Search messages')"
                    autofocus
                    class="h-9.5 rounded-[10px] bg-muted/40 pl-9"
                />
            </div>

            <!-- facet bar -->
            <div class="flex flex-wrap items-center gap-2" data-test="facet-bar">
                <!-- author facet -->
                <span
                    v-if="authorName !== null"
                    class="inline-flex h-7 items-center gap-1.5 rounded-full bg-primary py-0 pr-1.5 pl-1.5 text-xs font-medium text-primary-foreground"
                    data-test="facet-author"
                >
                    <span
                        class="flex size-4.5 items-center justify-center rounded-full bg-primary-foreground/20 text-[7.5px] font-semibold"
                        aria-hidden="true"
                        >{{ getInitials(authorName) }}</span
                    >
                    {{ authorName }}
                    <button
                        type="button"
                        class="flex size-4 items-center justify-center rounded-full text-primary-foreground/70 hover:text-primary-foreground"
                        :aria-label="$t('Remove author filter')"
                        @click="clearAuthor"
                    >
                        <X class="size-3" aria-hidden="true" />
                    </button>
                </span>
                <DropdownMenu v-else>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="inline-flex h-7 items-center gap-1.5 rounded-full border border-border px-3 text-xs font-medium text-muted-foreground hover:text-foreground"
                            data-test="facet-author-picker"
                        >
                            {{ $t('Author') }}
                            <ChevronDown class="size-3" aria-hidden="true" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="w-56 p-1.5">
                        <Input
                            v-model="authorFilter"
                            :placeholder="$t('Filter people…')"
                            :aria-label="$t('Filter people')"
                            class="mb-1 h-8 text-xs"
                            @keydown.stop
                        />
                        <div class="max-h-56 overflow-y-auto">
                            <button
                                v-for="member in filteredMembers"
                                :key="member.id"
                                type="button"
                                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[13px] hover:bg-accent"
                                data-test="facet-author-option"
                                @click="setAuthor(member.id)"
                            >
                                <span
                                    class="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[8px] font-semibold text-primary"
                                    aria-hidden="true"
                                    >{{ getInitials(member.name) }}</span
                                >
                                <span class="truncate">{{ member.name }}</span>
                            </button>
                        </div>
                    </DropdownMenuContent>
                </DropdownMenu>

                <!-- channel facet -->
                <span
                    v-if="channelName !== null"
                    class="inline-flex h-7 items-center gap-1.5 rounded-full bg-primary px-3 text-xs font-medium text-primary-foreground"
                    data-test="facet-channel"
                >
                    <span aria-hidden="true" class="text-brass">#</span
                    >{{ channelName }}
                    <button
                        type="button"
                        class="flex size-4 items-center justify-center rounded-full text-primary-foreground/70 hover:text-primary-foreground"
                        :aria-label="$t('Remove channel filter')"
                        @click="clearChannel"
                    >
                        <X class="size-3" aria-hidden="true" />
                    </button>
                </span>
                <DropdownMenu v-else>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="inline-flex h-7 items-center gap-1.5 rounded-full border border-border px-3 text-xs font-medium text-muted-foreground hover:text-foreground"
                            data-test="facet-channel-picker"
                        >
                            <span
                                aria-hidden="true"
                                class="text-muted-foreground"
                                >#</span
                            >{{ $t('Channel') }}
                            <ChevronDown class="size-3" aria-hidden="true" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="w-56 p-1.5">
                        <Input
                            v-model="channelFilter"
                            :placeholder="$t('Filter channels…')"
                            :aria-label="$t('Filter channels')"
                            class="mb-1 h-8 text-xs"
                            @keydown.stop
                        />
                        <div class="max-h-56 overflow-y-auto">
                            <button
                                v-for="channel in filteredChannels"
                                :key="channel.id"
                                type="button"
                                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[13px] hover:bg-accent"
                                data-test="facet-channel-option"
                                @click="setChannel(channel.id)"
                            >
                                <Lock
                                    v-if="channel.visibility === 'private'"
                                    class="size-3 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span
                                    v-else
                                    aria-hidden="true"
                                    class="text-brass"
                                    >#</span
                                >
                                <span class="truncate">{{ channel.name }}</span>
                            </button>
                        </div>
                    </DropdownMenuContent>
                </DropdownMenu>

                <!-- date facet -->
                <span
                    v-if="dateChipLabel !== null"
                    class="inline-flex h-7 items-center gap-1.5 rounded-full bg-primary py-0 pr-1.5 pl-3 text-xs font-medium text-primary-foreground"
                    data-test="facet-date"
                >
                    <Calendar class="size-3 text-brass" aria-hidden="true" />
                    {{ dateChipLabel }}
                    <button
                        type="button"
                        class="flex size-4 items-center justify-center rounded-full text-primary-foreground/70 hover:text-primary-foreground"
                        :aria-label="$t('Remove date filter')"
                        @click="clearDate"
                    >
                        <X class="size-3" aria-hidden="true" />
                    </button>
                </span>
                <DropdownMenu v-else>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="inline-flex h-7 items-center gap-1.5 rounded-full border border-border px-3 text-xs font-medium text-muted-foreground hover:text-foreground"
                            data-test="facet-date-picker"
                        >
                            <Calendar class="size-3" aria-hidden="true" />
                            {{ $t('Date') }}
                            <ChevronDown class="size-3" aria-hidden="true" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="w-60 p-1.5">
                        <button
                            v-for="preset in datePresets"
                            :key="preset.key"
                            type="button"
                            class="flex w-full items-center rounded-md px-2 py-1.5 text-left text-[13px] hover:bg-accent"
                            data-test="facet-date-preset"
                            @click="setDateRange(preset.after, preset.before)"
                        >
                            {{ preset.label }}
                        </button>
                        <button
                            type="button"
                            class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[13px] hover:bg-accent"
                            data-test="facet-date-custom"
                            @click="showCustomRange = !showCustomRange"
                        >
                            <Calendar class="size-3" aria-hidden="true" />
                            {{ $t('Custom…') }}
                        </button>
                        <div
                            v-if="showCustomRange"
                            class="flex flex-col gap-1.5 border-t border-border px-2 pt-2"
                            @click.stop
                        >
                            <label
                                class="flex flex-col gap-1 text-[11px] text-muted-foreground"
                            >
                                {{ $t('After') }}
                                <input
                                    type="date"
                                    :value="after ?? ''"
                                    class="rounded-md border border-border bg-background px-2 py-1 text-xs text-foreground"
                                    @change="
                                        setDateRange(
                                            ($event.target as HTMLInputElement)
                                                .value || null,
                                            before,
                                        )
                                    "
                                />
                            </label>
                            <label
                                class="flex flex-col gap-1 text-[11px] text-muted-foreground"
                            >
                                {{ $t('Before') }}
                                <input
                                    type="date"
                                    :value="before ?? ''"
                                    class="rounded-md border border-border bg-background px-2 py-1 text-xs text-foreground"
                                    @change="
                                        setDateRange(
                                            after,
                                            ($event.target as HTMLInputElement)
                                                .value || null,
                                        )
                                    "
                                />
                            </label>
                        </div>
                    </DropdownMenuContent>
                </DropdownMenu>

                <button
                    v-if="hasFilters"
                    type="button"
                    class="ml-1 border-b border-dotted border-muted-foreground/60 pb-px text-xs text-muted-foreground hover:text-foreground"
                    data-test="facet-clear-all"
                    @click="clearAllFilters"
                >
                    {{ $t('Clear all') }}
                </button>

                <span
                    v-if="props.query !== '' && resultCount > 0"
                    class="ml-auto text-xs text-muted-foreground"
                >
                    {{
                        resultCount === 1
                            ? $t('1 result')
                            : $t(':count results', { count: resultCount })
                    }}
                </span>
            </div>

            <!-- empty state -->
            <p
                v-if="props.query === ''"
                class="pt-16 text-center text-sm text-muted-foreground"
            >
                {{ $t('Search your channels for messages.') }}
            </p>

            <!-- zero-result state -->
            <div
                v-else-if="resultCount === 0"
                data-test="search-empty"
                class="flex flex-col items-center gap-2.5 pt-16 text-center"
            >
                <Search class="size-6 text-muted-foreground" aria-hidden="true" />
                <span class="text-sm font-semibold text-foreground">
                    {{
                        $t('No matches for “:query” with these filters', {
                            query: props.query,
                        })
                    }}
                </span>
                <span
                    v-if="activeFilterSummary !== ''"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        $t('Searched :filters', { filters: activeFilterSummary })
                    }}
                </span>
                <div class="mt-1 flex gap-2">
                    <Button
                        v-if="hasFilters"
                        variant="outline"
                        size="pill"
                        data-test="search-clear-filters"
                        @click="clearAllFilters"
                    >
                        {{ $t('Clear filters') }}
                    </Button>
                    <Button
                        v-if="channelName !== null"
                        size="pill"
                        data-test="search-all-channels"
                        @click="searchAllChannels"
                    >
                        {{ $t('Search all channels') }}
                    </Button>
                </div>
            </div>

            <!-- results, grouped by recency -->
            <div v-else class="flex flex-col pb-10">
                <template v-for="group in groups" :key="group.key">
                    <span
                        class="px-1 pt-3 pb-1.5 text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground uppercase"
                    >
                        {{ group.label }}
                    </span>
                    <Link
                        v-for="result in group.results"
                        :key="result.message.id"
                        :href="jumpHref(result)"
                        data-test="search-result"
                        class="flex gap-3 rounded-xl px-2.5 py-3 hover:bg-accent/50"
                    >
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-primary/10 text-[12px] font-semibold text-primary select-none"
                            aria-hidden="true"
                        >
                            {{ getInitials(result.message.user.name) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2 text-[13px]">
                                <span class="font-semibold text-foreground">
                                    {{ result.message.user.name }}
                                </span>
                                <span class="text-muted-foreground">
                                    <span class="text-brass">#</span
                                    >{{ result.channelName }}
                                </span>
                                <span
                                    class="ml-auto shrink-0 text-[11px] text-muted-foreground"
                                >
                                    {{
                                        formatTimestamp(result.message.createdAt)
                                    }}
                                </span>
                            </div>
                            <p
                                class="search-snippet mt-0.5 line-clamp-2 text-[14px] leading-[1.55] break-words text-foreground/90"
                                v-html="result.snippet"
                            ></p>
                            <span
                                v-if="result.message.threadReplyCount > 0"
                                class="mt-1.5 inline-flex items-center gap-1.5 text-[11px] text-muted-foreground"
                            >
                                <MessagesSquare
                                    class="size-3"
                                    aria-hidden="true"
                                />
                                {{
                                    result.message.threadReplyCount === 1
                                        ? $t('in thread · 1 reply')
                                        : $t('in thread · :count replies', {
                                              count: result.message
                                                  .threadReplyCount,
                                          })
                                }}
                            </span>
                        </div>
                    </Link>
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
/*
 * Server-sanitized `<mark>` highlights ride in via v-html; style them with the
 * brass reaction-pill tokens, which carry an AA-contrast foreground in both
 * light and dark themes.
 */
.search-snippet :deep(mark) {
    background-color: var(--brass-fill);
    color: var(--brass-fill-foreground);
    border-radius: 3px;
    padding: 0 2px;
    font-weight: 600;
}
</style>
