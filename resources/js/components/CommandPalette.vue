<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { show } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import SwitcherChannels from '@/components/switcher/SwitcherChannels.vue';
import SwitcherCommands from '@/components/switcher/SwitcherCommands.vue';
import SwitcherField from '@/components/switcher/SwitcherField.vue';
import SwitcherMessages from '@/components/switcher/SwitcherMessages.vue';
import SwitcherPeople from '@/components/switcher/SwitcherPeople.vue';
import { Command, CommandList } from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { rankCommands } from '@/composables/commands';
import type { PaletteCommand } from '@/composables/commands';
import {
    rankChannels,
    rankChannelsByActivity,
    scoreChannelName,
} from '@/composables/quickSwitcher';
import { useCommandPaletteSearch } from '@/composables/useCommandPaletteSearch';
import { useIsMobile } from '@/composables/useIsMobile';
import { useOpenDirectMessage } from '@/composables/useOpenDirectMessage';
import { rankPeople } from '@/lib/peopleDirectory';
import type { MessageSearchResult } from '@/types';
import type { Channel } from '@/types/channels';
import type { PersonRef } from '@/types/people';

const props = defineProps<{
    channels: Channel[];
    members: PersonRef[];
    currentUserId: string;
    teamSlug: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const {
    query,
    trimmedQuery,
    searchText,
    messageResults,
    isSearchingMessages,
    clear,
    handOffToSearch,
} = useCommandPaletteSearch({
    teamSlug: () => props.teamSlug,
    members: () => props.members,
    channels: () => props.channels,
});

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

// Team members ranked next to channels; choosing one opens/creates their DM.
const { openDirectMessage } = useOpenDirectMessage(() => props.teamSlug);

const peopleResults = computed(() =>
    rankPeople(props.members, query.value, props.currentUserId),
);

/**
 * The verbs, ranked on the same trimmed query the destinations rank on. The
 * predicates run in here rather than once at import, so a row appears and
 * disappears with the state it is gated on.
 */
const commandResults = computed(() => rankCommands(trimmedQuery.value));

const page = usePage();

/**
 * The slash command the query plainly names, or null. Slash commands are never
 * rows here — their context requires a channel and every one of their results is
 * a message — so a query naming one is answered by a line saying where it lives.
 *
 * Exact equality only, on the trimmed query with at most one leading `/` gone.
 * Prefix matching would pop the line on `g`; matching outright means it is
 * silent for everyone who was not already asking for a slash command.
 */
const namedSlashCommand = computed<string | null>(
    () =>
        page.props.slashCommands?.find(
            (command) =>
                command.name.toLowerCase() ===
                trimmedQuery.value.replace(/^\//, '').toLowerCase(),
        )?.name ?? null,
);

/** The groups whose place in the list the query decides. */
type RankedGroup = 'channels' | 'people' | 'commands';

/** The declared order, which an empty query and every tie fall back to. */
const DECLARED_ORDER: readonly RankedGroup[] = [
    'channels',
    'people',
    'commands',
];

/**
 * How well a group answers the query: the best any of its own rows scores. A
 * group with nothing matching scores 0 — the same as every group scores on an
 * empty query — and does not render at all.
 */
function bestScore(names: string[]): number {
    return names.reduce(
        (best, name) =>
            Math.max(best, scoreChannelName(name, trimmedQuery.value) ?? 0),
        0,
    );
}

/**
 * The order the groups take, which is a decision about `Enter`: the list
 * highlights its first row on every keystroke, so the group on top *is* the
 * default action. Destinations keep it until something plainly names a verb.
 *
 * Messages never enters this sort and is pinned last: it arrives on a debounce,
 * and a group jumping to the top a beat after the typing stopped would move the
 * Enter target under the viewer's hands.
 */
const groupOrder = computed<RankedGroup[]>(() => {
    const rows: Record<RankedGroup, string[]> = {
        channels: channelResults.value.map((channel) => channel.name),
        people: peopleResults.value.map((person) => person.name),
        commands: commandResults.value.map((command) => command.title),
    };

    return DECLARED_ORDER.map((group) => ({
        group,
        score: bestScore(rows[group]),
    }))
        .sort((a, b) => b.score - a.score)
        .map((scored) => scored.group);
});

// Reset everything on dismiss so the palette always reopens blank.
watch(open, (isOpen) => {
    if (!isOpen) {
        clear();
    }
});

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
    handOffToSearch();
}

function runCommand(command: PaletteCommand): void {
    open.value = false;
    command.run();
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
                <DialogTitle>{{ $t('Command palette') }}</DialogTitle>
                <DialogDescription>{{
                    $t('Search channels, people and messages, or run a command')
                }}</DialogDescription>
            </DialogHeader>
            <Command>
                <SwitcherField
                    v-model="query"
                    :is-mobile="isMobile"
                    @cancel="open = false"
                />
                <CommandList
                    :ariaLabel="$t('Command palette')"
                    class="max-md:max-h-none max-md:flex-1 max-md:p-1.5"
                >
                    <template v-for="group in groupOrder" :key="group">
                        <SwitcherChannels
                            v-if="group === 'channels'"
                            :channels="channelResults"
                            :query="query"
                            :is-mobile="isMobile"
                            @select="selectChannel"
                        />

                        <SwitcherPeople
                            v-else-if="group === 'people'"
                            :people="peopleResults"
                            :query="query"
                            :is-mobile="isMobile"
                            @select="selectPerson"
                        />

                        <SwitcherCommands
                            v-else
                            :commands="commandResults"
                            :query="query"
                            :is-mobile="isMobile"
                            @select="runCommand"
                        />
                    </template>

                    <SwitcherMessages
                        v-if="searchText !== ''"
                        :results="messageResults"
                        :is-searching="isSearchingMessages"
                        :search-text="searchText"
                        @select="selectMessage"
                        @see-all="seeAllResults"
                    />
                </CommandList>
                <!-- Where a query that names a slash command actually leads. A
                     plain paragraph rather than a row, so it sits in no group,
                     in no sort, and cannot move what Enter runs — the same
                     construction the "No messages match" line has always used.
                     Below the breakpoint it takes the standing hint's place:
                     one line in this slot, always. -->
                <p
                    v-if="namedSlashCommand !== null"
                    data-test="quick-switcher-slash-command-hint"
                    class="shrink-0 px-4 pt-2.5 pb-3 text-center text-[11.5px] text-muted-foreground"
                >
                    {{
                        $t(
                            '/:name is a message command. Type it in a channel to use it.',
                            { name: namedSlashCommand },
                        )
                    }}
                </p>
                <!-- The design's standing explanation of the empty-query list:
                     it doubles as the overlay's ranking hint, so it stays put
                     rather than living inside the scrolling results. -->
                <p
                    v-else-if="isMobile"
                    data-test="quick-switcher-ranking-hint"
                    class="shrink-0 px-4 pt-2.5 pb-3 text-center text-[11.5px] text-muted-foreground"
                >
                    {{
                        $t(
                            'Recent shows before you type · commands rank as you type',
                        )
                    }}
                </p>
            </Command>
        </DialogContent>
    </Dialog>
</template>
