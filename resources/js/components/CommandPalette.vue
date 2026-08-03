<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlarmClock } from '@lucide/vue';
import { computed, watch } from 'vue';
import { show } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import SwitcherChannels from '@/components/switcher/SwitcherChannels.vue';
import SwitcherField from '@/components/switcher/SwitcherField.vue';
import SwitcherMessages from '@/components/switcher/SwitcherMessages.vue';
import SwitcherPeople from '@/components/switcher/SwitcherPeople.vue';
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
    rankChannels,
    rankChannelsByActivity,
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

const emit = defineEmits<{
    /** The viewer picked the "Reminders" action; the layout opens its panel. */
    openReminders: [];
}>();

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

                    <SwitcherChannels
                        :channels="channelResults"
                        :query="query"
                        :is-mobile="isMobile"
                        @select="selectChannel"
                    />

                    <SwitcherPeople
                        :people="peopleResults"
                        :query="query"
                        :is-mobile="isMobile"
                        @select="selectPerson"
                    />

                    <SwitcherMessages
                        v-if="searchText !== ''"
                        :results="messageResults"
                        :is-searching="isSearchingMessages"
                        :search-text="searchText"
                        @select="selectMessage"
                        @see-all="seeAllResults"
                    />
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
