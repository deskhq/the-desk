<script setup lang="ts">
/**
 * PROTOTYPE — throwaway. Variant B — "Groups stay whole; the query reorders them."
 *
 * Linear's answer, and the third option #1211's question did not name. Item
 * order inside a group never changes, so `rankChannels` and `rankPeople` keep
 * owning their own lists. What moves is the *group*: whichever group holds the
 * best-scoring row leads the list, so it is what `Enter` runs.
 *
 * Deliberately A-plus-one-change, so the reorder can be judged on its own
 * rather than against a whole different list. Type "dark" and Commands leads;
 * type "gen" and Channels takes it straight back.
 *
 * Messages stay pinned last regardless — they arrive on a debounce, and a group
 * that jumps to the top a beat after you stop typing would move the Enter
 * target under your hands.
 */
import SwitcherChannels from '@/components/switcher/SwitcherChannels.vue';
import SwitcherMessages from '@/components/switcher/SwitcherMessages.vue';
import SwitcherPeople from '@/components/switcher/SwitcherPeople.vue';
import { CommandGroup } from '@/components/ui/command';
import {
    rankChannels,
    rankChannelsByActivity,
    scoreChannelName,
} from '@/composables/quickSwitcher';
import { rankPeople } from '@/lib/peopleDirectory';
import type { MessageSearchResult } from '@/types';
import type { Channel } from '@/types/channels';
import type { PersonRef } from '@/types/people';
import { computed } from 'vue';
import PrototypeCommandRow from './PrototypeCommandRow.vue';
import { availableCommands } from './paletteCommands';
import type { PrototypeCommand } from './paletteCommands';

const props = defineProps<{
    channels: Channel[];
    members: PersonRef[];
    currentUserId: string;
    query: string;
    isMobile: boolean;
    messageResults: MessageSearchResult[];
    isSearchingMessages: boolean;
    searchText: string;
}>();

defineEmits<{
    selectChannel: [channel: Channel];
    selectPerson: [id: string];
    selectMessage: [result: MessageSearchResult];
    seeAll: [];
    runCommand: [command: PrototypeCommand];
}>();

const trimmed = computed(() => props.query.trim().replace(/^#+/, ''));

const channelResults = computed(() =>
    props.isMobile
        ? rankChannelsByActivity(props.channels, props.query)
        : rankChannels(props.channels, props.query),
);

const peopleResults = computed(() =>
    rankPeople(props.members, props.query, props.currentUserId),
);

const commandResults = computed(() =>
    availableCommands()
        .map((command) => ({
            command,
            score: scoreChannelName(command.title, trimmed.value),
        }))
        .filter(
            (scored): scored is { command: PrototypeCommand; score: number } =>
                scored.score !== null,
        )
        .sort((a, b) => b.score - a.score)
        .map((scored) => scored.command),
);

/** The best score any row in a group reaches, which is what the group sorts on. */
function best(names: string[]): number {
    return names.reduce(
        (highest, name) =>
            Math.max(highest, scoreChannelName(name, trimmed.value) ?? -1),
        -1,
    );
}

/**
 * Group order, best group first. An empty query scores every group 0, so the
 * declared order survives — Channels, People, Commands, as today.
 */
const groupOrder = computed<('channels' | 'people' | 'commands')[]>(() => {
    const scored = [
        {
            key: 'channels' as const,
            score: best(channelResults.value.map((c) => c.name)),
        },
        {
            key: 'people' as const,
            score: best(peopleResults.value.map((p) => p.name)),
        },
        {
            key: 'commands' as const,
            score: best(commandResults.value.map((c) => c.title)),
        },
    ];

    return scored
        .filter((group) => group.score > -1)
        .sort((a, b) => b.score - a.score)
        .map((group) => group.key);
});
</script>

<template>
    <template v-for="key in groupOrder" :key="key">
        <SwitcherChannels
            v-if="key === 'channels'"
            :channels="channelResults"
            :query="query"
            :is-mobile="isMobile"
            @select="$emit('selectChannel', $event)"
        />

        <SwitcherPeople
            v-else-if="key === 'people'"
            :people="peopleResults"
            :query="query"
            :is-mobile="isMobile"
            @select="$emit('selectPerson', $event)"
        />

        <CommandGroup
            v-else
            :heading="$t('Commands')"
            data-test="palette-commands-group"
        >
            <PrototypeCommandRow
                v-for="command in commandResults"
                :key="command.id"
                :command="command"
                :is-mobile="isMobile"
                :show-keys="true"
                @select="$emit('runCommand', $event)"
            />
        </CommandGroup>
    </template>

    <SwitcherMessages
        v-if="searchText !== ''"
        :results="messageResults"
        :is-searching="isSearchingMessages"
        :search-text="searchText"
        @select="$emit('selectMessage', $event)"
        @see-all="$emit('seeAll')"
    />
</template>
