<script setup lang="ts">
/**
 * PROTOTYPE — throwaway. Variant C — "One flat ranked list, and the empty state is the menu."
 *
 * The expensive option, and the only one that rethinks the list rather than
 * rearranging it. Groups go away: commands, channels and people are scored by
 * one scorer and interleaved, each row carrying a small trailing tag so you can
 * still tell what a thing is. Messages remain a trailing section because they
 * arrive late and cannot be scored against the rest.
 *
 * The empty query stops being "everything" and becomes the verb menu — the
 * palette opens as a list of things you can do, and typing turns it into a list
 * of things you can reach. That is the whole discoverability argument in one
 * screen, and the whole cost argument too: today's palette opens on your
 * channels, and this takes that away.
 */
import SwitcherMessages from '@/components/switcher/SwitcherMessages.vue';
import { CommandGroup, CommandItem } from '@/components/ui/command';
import { getInitials } from '@/composables/useInitials';
import { scoreChannelName } from '@/composables/quickSwitcher';
import { rankPeople } from '@/lib/peopleDirectory';
import type { MessageSearchResult } from '@/types';
import type { Channel } from '@/types/channels';
import type { PersonRef } from '@/types/people';
import { computed } from 'vue';
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

const emit = defineEmits<{
    selectChannel: [channel: Channel];
    selectPerson: [id: string];
    selectMessage: [result: MessageSearchResult];
    seeAll: [];
    runCommand: [command: PrototypeCommand];
}>();

/** How many interleaved rows the list shows before the messages section. */
const ROW_LIMIT = 12;

type Row =
    | { kind: 'command'; key: string; name: string; command: PrototypeCommand }
    | { kind: 'channel'; key: string; name: string; channel: Channel }
    | {
          kind: 'person';
          key: string;
          name: string;
          id: string;
          isSelf: boolean;
      };

const trimmed = computed(() => props.query.trim().replace(/^#+/, ''));

const commandRows = computed<Row[]>(() =>
    availableCommands().map((command) => ({
        kind: 'command',
        key: `command:${command.id}`,
        name: command.title,
        command,
    })),
);

const allRows = computed<Row[]>(() => [
    ...commandRows.value,
    ...props.channels.map((channel): Row => ({
        kind: 'channel',
        key: `channel:${channel.id}`,
        name: channel.name,
        channel,
    })),
    ...rankPeople(props.members, '', props.currentUserId).map(
        (person): Row => ({
            kind: 'person',
            key: `person:${person.id}`,
            name: person.name,
            id: person.id,
            isSelf: person.isSelf,
        }),
    ),
]);

/** Commands lead a tie, because a verb has nowhere else in the list to be found. */
const KIND_ORDER: Record<Row['kind'], number> = {
    command: 0,
    channel: 1,
    person: 2,
};

const rows = computed<Row[]>(() => {
    if (trimmed.value === '') {
        return commandRows.value;
    }

    return allRows.value
        .map((row) => ({
            row,
            score: scoreChannelName(row.name, trimmed.value),
        }))
        .filter(
            (scored): scored is { row: Row; score: number } =>
                scored.score !== null,
        )
        .sort(
            (a, b) =>
                b.score - a.score ||
                KIND_ORDER[a.row.kind] - KIND_ORDER[b.row.kind] ||
                a.row.name.localeCompare(b.row.name),
        )
        .slice(0, ROW_LIMIT)
        .map((scored) => scored.row);
});

function select(row: Row): void {
    if (row.kind === 'command') {
        emit('runCommand', row.command);
    } else if (row.kind === 'channel') {
        emit('selectChannel', row.channel);
    } else {
        emit('selectPerson', row.id);
    }
}
</script>

<template>
    <CommandGroup data-test="palette-flat-list">
        <CommandItem
            v-for="row in rows"
            :key="row.key"
            :value="row.key"
            :data-test="
                row.kind === 'command' ? 'palette-command' : 'palette-row'
            "
            class="group h-9.5 gap-2 rounded-lg px-2.5 max-md:h-11.5 max-md:gap-2.5 max-md:rounded-[11px] max-md:px-3 max-md:text-[15px] md:data-[highlighted]:bg-primary md:data-[highlighted]:text-primary-foreground"
            @select="select(row)"
        >
            <component
                :is="row.command.icon"
                v-if="row.kind === 'command'"
                class="size-4 shrink-0 text-muted-foreground/70 group-data-[highlighted]:text-brass"
            />
            <span
                v-else-if="row.kind === 'channel'"
                class="w-4 shrink-0 text-center font-semibold text-muted-foreground group-data-[highlighted]:text-brass"
                aria-hidden="true"
                >#</span
            >
            <span
                v-else
                class="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[9px] font-semibold text-primary select-none"
                aria-hidden="true"
                >{{ getInitials(row.name) }}</span
            >

            <span class="truncate">{{
                row.kind === 'command'
                    ? $t(row.name)
                    : row.kind === 'person' && row.isSelf
                      ? $t('You')
                      : row.name
            }}</span>

            <span
                class="ml-auto shrink-0 font-serif text-[11px] text-muted-foreground italic group-data-[highlighted]:text-primary-foreground/70"
                >{{
                    row.kind === 'command'
                        ? $t('Command')
                        : row.kind === 'channel'
                          ? $t('Channel')
                          : $t('Person')
                }}</span
            >
        </CommandItem>
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
