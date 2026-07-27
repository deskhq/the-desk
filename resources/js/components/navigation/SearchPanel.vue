<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Search, X } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import SearchFacetBar from '@/components/navigation/SearchFacetBar.vue';
import type { FacetChannel } from '@/components/navigation/SearchFacetBar.vue';
import SearchResultList from '@/components/navigation/SearchResultList.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useDebouncedPost } from '@/composables/useDebouncedPost';
import { urlForDestination } from '@/composables/useNavPanel';
import { useTranslations } from '@/composables/useTranslations';
import { formatCalendarDate } from '@/lib/datetime';
import {
    SEARCH_PANEL_PROPS,
    SEARCH_WORKSPACE_CHANNELS_PROP,
    searchFingerprint,
    searchParamsFromUrl,
    urlForSearchParams,
} from '@/lib/searchPanel';
import { parseSearchQuery } from '@/lib/searchTokens';
import type { SearchParams, TokenLookup } from '@/lib/searchTokens';

/**
 * The Search destination: #391's faceted, highlighted, date-grouped message
 * search, reflowed into the dock's 300px column while the channel behind it
 * stays live.
 *
 * The criteria are read from the URL rather than held here, so "the URL is the
 * state" survives the move: a shared link, a cold reload, a history traversal
 * and a hand-off from the ⌘K palette all seed the panel the same way, and the
 * matches the server sends are the ones those very params selected. Only the
 * text input keeps a local copy — it has to, to be typed into — and it is
 * re-seeded whenever the URL's query changes to something this panel did not
 * write.
 */
const page = usePage();

const { t } = useTranslations();

/** The criteria pinned on the current URL, normalized. */
const criteria = computed<SearchParams>(() => searchParamsFromUrl(page.url));

const query = computed(() => criteria.value.q ?? '');
const authorId = computed(() => criteria.value.from ?? null);
const channelId = computed(() => criteria.value.in ?? null);
const after = computed(() => criteria.value.after ?? null);
const before = computed(() => criteria.value.before ?? null);
const scope = computed(() => criteria.value.scope ?? 'team');

const results = computed(() => page.props.searchResults);
/** The criteria the loaded matches answer, absent until they have been pulled. */
const applied = computed(() => page.props.searchCriteria);
const resultCount = computed(() => results.value?.length ?? 0);
const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');
const viewerTimeZone = computed(
    () => page.props.auth.user.timezone ?? undefined,
);

/**
 * The current team's channels and members feed the facet pickers and the token
 * parser; both ride on every workspace request. The teams list decides whether
 * the workspace-scope control has anything to widen to.
 */
const channels = computed(() => page.props.channels ?? []);
const members = computed(() => page.props.teamMembers ?? []);
const workspaceChannels = computed(() => page.props.searchWorkspaceChannels);
const showScopeControl = computed(() => (page.props.teams ?? []).length > 1);

/**
 * In cross-team mode the channel facet lists the union across all the viewer's
 * teams; otherwise just this team's channels. Either way every known channel can
 * resolve a chip label, so a shared link's channel id renders its name.
 */
const channelOptions = computed<FacetChannel[]>(() =>
    scope.value === 'all'
        ? (workspaceChannels.value ?? []).map((channel) => ({
              id: channel.id,
              name: channel.name,
              isPrivate: channel.visibility === 'private',
              teamName: channel.teamName,
          }))
        : channels.value.map((channel) => ({
              id: channel.id,
              name: channel.name,
              isPrivate: channel.visibility === 'private',
              teamName: null,
          })),
);

/**
 * Every channel the shell knows about, whichever scope it came from — the chip
 * label of a shared link's channel id has to resolve even before the viewer has
 * widened the scope that would list it.
 */
const knownChannels = computed(() => [
    ...channels.value,
    ...(workspaceChannels.value ?? []),
]);

const channelNameById = computed(
    () =>
        new Map(
            knownChannels.value.map((channel) => [channel.id, channel.name]),
        ),
);

/** What the `from:` / `in:` tokens in the input resolve against. */
const tokenLookup = computed<TokenLookup>(() => ({
    members: members.value,
    channels: knownChannels.value,
}));

/** The applied chip labels, resolved against whatever the shell knows. */
const authorName = computed(
    () =>
        members.value.find((member) => member.id === authorId.value)?.name ??
        null,
);
const channelName = computed(() =>
    channelId.value === null
        ? null
        : (channelNameById.value.get(channelId.value) ?? null),
);
const dateLabel = computed(() => {
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

/** The zero-result summary names the active filters back to the viewer. */
const activeFilterSummary = computed(() => {
    const parts: string[] = [];

    if (channelName.value !== null) {
        parts.push(`#${channelName.value}`);
    }

    if (authorName.value !== null) {
        parts.push(t('from :name', { name: authorName.value }));
    }

    if (dateLabel.value !== null) {
        parts.push(dateLabel.value);
    }

    return parts.join(', ');
});

/** Cancels the search request currently in flight, if there is one. */
let cancelLoad: (() => void) | null = null;

/**
 * The criteria the last request asked for, which is not always the ones the
 * loaded matches answer: while a request is in flight the two differ, and a
 * client/server disagreement about how to read a URL would otherwise leave the
 * sync below asking for the same thing forever.
 */
let requested = '';

/** The text field's own copy of the query, which has to be typed into. */
const term = ref(query.value);

/**
 * Whether the next change to {@link term} is one this panel made itself, and so
 * must not be scheduled straight back at the server. Only ever set through
 * {@link setTerm}, which leaves it alone when the value is unchanged — a flag set
 * for a write that never lands would swallow the viewer's next keystroke.
 */
let skipNextSchedule = false;

/** Write the field without scheduling the write straight back at the server. */
function setTerm(value: string): void {
    if (term.value === value) {
        return;
    }

    skipNextSchedule = true;
    term.value = value;
}

/** Debounce keystrokes; a facet change (chip, picker, scope) runs immediately. */
const debouncedTerm = useDebouncedPost((raw: string) => commitTerm(raw), {
    delay: 300,
});

watch(term, (value) => {
    if (skipNextSchedule) {
        skipNextSchedule = false;

        return;
    }

    debouncedTerm.schedule(value);
});

/**
 * Run a set of criteria: pin them on the URL and pull the matches back.
 *
 * The destination is pinned here as well as by the rail, because the props ride
 * on `?nav=search`: the rail's own `router.replace` is a client-side visit whose
 * history write lands a tick later, so a request built from `page.url` alone
 * could still be asking as the conversation list (#947). The channel union is
 * only asked for while it is missing — it spans every workspace the viewer is
 * in, and nothing about it changes as facets do.
 */
function run(params: SearchParams): void {
    requested = searchFingerprint(params);

    router.get(
        urlForSearchParams(urlForDestination(page.url, 'search'), params),
        {},
        {
            only:
                workspaceChannels.value === undefined
                    ? [...SEARCH_PANEL_PROPS, SEARCH_WORKSPACE_CHANNELS_PROP]
                    : [...SEARCH_PANEL_PROPS],
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

/**
 * Apply a change on top of the criteria already on the URL, carrying whatever is
 * in the field along with it.
 *
 * The field rather than the URL's query, because a facet can be picked while a
 * keystroke is still waiting out its debounce: taking the URL would run the facet
 * against the *previous* query and drop what has been typed since. Committing the
 * field makes that pending keystroke redundant, so it is dropped.
 */
function apply(changes: Partial<SearchParams>): void {
    debouncedTerm.cancel();

    run({ ...criteria.value, q: term.value.trim(), ...changes });
}

// A panel the viewer has already left must not be allowed to finish: this visit
// pins `?nav=search` on the URL, and landing after a switch back to the
// conversation list would drag the destination param back with it.
onBeforeUnmount(() => {
    cancelLoad?.();
});

/**
 * Pull the matches whenever the URL asks for something the loaded ones do not
 * answer. That covers every way the criteria change without this panel writing
 * them: a cold open on a destination whose props never came, a history
 * traversal, and the ⌘K palette handing a query over.
 *
 * The input is re-seeded here and only here, so an in-flight keystroke's echo
 * can never clobber what has been typed since it left.
 */
function syncFromUrl(): void {
    const wanted = searchFingerprint(criteria.value);

    const answered =
        results.value !== undefined &&
        wanted === searchFingerprint(applied.value ?? {});

    if (answered || wanted === requested) {
        return;
    }

    setTerm(query.value);
    run(criteria.value);
}

watch([criteria, applied], syncFromUrl);

// Opening the destination is a client-side visit, so the panel arrives without
// its props and fetches them itself. A deep link (`?nav=search&q=…`) already
// carries matching ones, and refetching would only run the same query twice.
onMounted(async () => {
    syncFromUrl();

    // The field renders as a shadcn `<Input>`, so its DOM node is resolved by
    // selector once mounted rather than through a template ref (which would hand
    // back the component instance, not the `<input>`).
    await nextTick();
    document
        .querySelector<HTMLInputElement>('[data-test="search-input"]')
        ?.focus();
});

/**
 * Recognized `from:` / `in:` / `before:` / `after:` tokens promote to facet chips
 * and are stripped from the visible input; unknown tokens stay literal.
 */
function commitTerm(raw: string): void {
    const parsed = parseSearchQuery(raw, tokenLookup.value);

    setTerm(parsed.text);

    apply({
        q: parsed.text,
        ...(parsed.from !== null ? { from: parsed.from } : {}),
        ...(parsed.in !== null ? { in: parsed.in } : {}),
        ...(parsed.after !== null ? { after: parsed.after } : {}),
        ...(parsed.before !== null ? { before: parsed.before } : {}),
    });
}

function setScope(next: 'team' | 'all'): void {
    // Narrowing back to the current team drops a channel facet belonging to
    // another workspace, so the search isn't filtered by an inapplicable channel.
    const keepsChannel =
        next === 'all' ||
        channelId.value === null ||
        channels.value.some((channel) => channel.id === channelId.value);

    apply({
        scope: next === 'all' ? 'all' : undefined,
        in: keepsChannel ? (channelId.value ?? undefined) : undefined,
    });
}

function clearTerm(): void {
    setTerm('');
    apply({ q: undefined });
}

function clearAllFilters(): void {
    apply({
        from: undefined,
        in: undefined,
        after: undefined,
        before: undefined,
    });
}

function searchAllChannels(): void {
    apply({ in: undefined });
}

function setRange(from: string | null, to: string | null): void {
    apply({ after: from ?? undefined, before: to ?? undefined });
}
</script>

<template>
    <div
        data-test="destination-panel-search"
        class="flex min-h-0 flex-1 flex-col"
    >
        <!-- The field stands in for the heading the other destinations carry:
             the design gives the column's whole top block to the search itself,
             so the panel is named for assistive tech instead. -->
        <h2 class="sr-only">{{ $t('Search messages') }}</h2>

        <div
            class="flex shrink-0 flex-col gap-2.25 border-b border-sidebar-border px-2.5 pt-3 pb-2 max-md:gap-3 max-md:px-3.5 max-md:pt-4 max-md:pb-2.5"
        >
            <div class="relative">
                <Search
                    class="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-sidebar-foreground/70 max-md:left-3.5 max-md:size-4.25"
                    aria-hidden="true"
                />
                <Input
                    v-model="term"
                    type="search"
                    :placeholder="$t('Search messages')"
                    :aria-label="$t('Search messages')"
                    data-test="search-input"
                    class="h-8.5 rounded-[10px] border-sidebar-border bg-sidebar-accent pr-8 pl-8 text-[13px] [&::-webkit-search-cancel-button]:hidden max-md:h-11.5 max-md:rounded-[13px] max-md:pr-11 max-md:pl-11 max-md:text-base"
                />
                <Button
                    v-if="term !== ''"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="search-clear"
                    :aria-label="$t('Clear search')"
                    class="absolute top-1/2 right-1.5 flex size-6 -translate-y-1/2 items-center justify-center rounded-full text-sidebar-foreground/70 hover:text-sidebar-foreground max-md:right-2 max-md:size-9"
                    @click="clearTerm"
                >
                    <X class="size-3.25 max-md:size-4" />
                </Button>
            </div>

            <!-- workspace scope (multi-team viewers only): two halves of one
                 control rather than the page's inline pills, so a long workspace
                 name cannot push the second option out of the column. -->
            <div
                v-if="showScopeControl"
                class="grid grid-cols-2 gap-0.5 rounded-full bg-sidebar-accent p-0.5"
                role="group"
                :aria-label="$t('Search scope')"
                data-test="scope-control"
            >
                <Button
                    variant="segmented"
                    size="none"
                    type="button"
                    class="h-6 min-w-0 rounded-full px-2 text-[11.5px] font-medium max-md:h-9 max-md:text-[13px]"
                    :aria-pressed="scope === 'team'"
                    data-test="scope-team"
                    @click="setScope('team')"
                >
                    <span class="truncate">{{
                        page.props.currentTeam?.name ?? $t('This workspace')
                    }}</span>
                </Button>
                <Button
                    variant="segmented"
                    size="none"
                    type="button"
                    class="h-6 min-w-0 rounded-full px-2 text-[11.5px] font-medium max-md:h-9 max-md:text-[13px]"
                    :aria-pressed="scope === 'all'"
                    data-test="scope-all"
                    @click="setScope('all')"
                >
                    <span class="truncate">{{ $t('All workspaces') }}</span>
                </Button>
            </div>

            <SearchFacetBar
                :author-name="authorName"
                :channel-name="channelName"
                :date-label="dateLabel"
                :after="after"
                :before="before"
                :members="members"
                :channels="channelOptions"
                :has-filters="hasFilters"
                @author="apply({ from: $event ?? undefined })"
                @channel="apply({ in: $event ?? undefined })"
                @range="setRange"
                @clear-all="clearAllFilters"
            />
        </div>

        <div
            class="flex min-h-0 flex-1 flex-col overflow-auto px-2.5 pb-3 max-md:px-3.5"
        >
            <p
                v-if="query === ''"
                data-test="search-idle"
                class="px-1 pt-10 text-center text-[12.5px] leading-relaxed text-sidebar-foreground/70"
            >
                {{ $t('Search your channels for messages.') }}
            </p>

            <div
                v-else-if="resultCount === 0"
                data-test="search-empty"
                class="flex flex-col items-center gap-2 px-1 pt-10 text-center"
            >
                <span
                    class="text-[12.5px] font-semibold text-sidebar-foreground"
                >
                    {{
                        $t('No matches for “:query” with these filters', {
                            query,
                        })
                    }}
                </span>
                <span
                    v-if="activeFilterSummary !== ''"
                    class="text-[11.5px] text-sidebar-foreground/70"
                >
                    {{
                        $t('Searched :filters', {
                            filters: activeFilterSummary,
                        })
                    }}
                </span>
                <div class="mt-1 flex flex-wrap justify-center gap-1.5">
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

            <template v-else>
                <p
                    data-test="search-result-count"
                    class="px-1 pt-1.5 pb-0.5 text-[10.5px] font-semibold tracking-[0.1em] text-sidebar-foreground/70 uppercase max-md:text-[11px]"
                >
                    {{
                        resultCount === 1
                            ? $t('1 result')
                            : $t(':count results', { count: resultCount })
                    }}
                </p>
                <SearchResultList
                    :results="results ?? []"
                    :current-team-slug="teamSlug"
                    :time-zone="viewerTimeZone"
                />
            </template>
        </div>
    </div>
</template>
