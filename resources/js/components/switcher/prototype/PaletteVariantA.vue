<script setup lang="ts">
/**
 * PROTOTYPE — throwaway. Variant A — "Commands are a fifth group, and it sits last."
 *
 * The cheapest possible answer. Nothing about ranking changes: each group keeps
 * its own scorer, group order is fixed, and Commands is appended below People.
 * The one thing it fixes is the `v-if="trimmedQuery === ''"` bug — the group
 * renders whether or not you have typed.
 *
 * The consequence to judge: because reka-ui highlights the first item in the
 * DOM on every keystroke, a destination always wins Enter. Typing "dark" and
 * hitting Enter jumps you to a channel whose name happens to contain those
 * letters, not to the theme verb — and no visible copy changes, so nothing
 * tells you the verbs are down there at all.
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
            score: scoreChannelName(command.title, props.query.trim()),
        }))
        .filter(
            (scored): scored is { command: PrototypeCommand; score: number } =>
                scored.score !== null,
        )
        .sort((a, b) => b.score - a.score)
        .map((scored) => scored.command),
);
</script>

<template>
    <SwitcherChannels
        :channels="channelResults"
        :query="query"
        :is-mobile="isMobile"
        @select="$emit('selectChannel', $event)"
    />

    <SwitcherPeople
        :people="peopleResults"
        :query="query"
        :is-mobile="isMobile"
        @select="$emit('selectPerson', $event)"
    />

    <CommandGroup
        v-if="commandResults.length > 0"
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

    <SwitcherMessages
        v-if="searchText !== ''"
        :results="messageResults"
        :is-searching="isSearchingMessages"
        :search-text="searchText"
        @select="$emit('selectMessage', $event)"
        @see-all="$emit('seeAll')"
    />
</template>
