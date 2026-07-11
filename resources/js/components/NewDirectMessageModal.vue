<script setup lang="ts">
import { Search } from '@lucide/vue';
import { ListboxFilter } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import {
    CommandDialog,
    CommandGroup,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { useInitials } from '@/composables/useInitials';
import { useOpenDirectMessage } from '@/composables/useOpenDirectMessage';
import { useTranslations } from '@/composables/useTranslations';
import { rankPeople } from '@/lib/peopleDirectory';
import type { PersonRef } from '@/types/people';

const props = defineProps<{
    teamSlug: string;
    members: PersonRef[];
    currentUserId: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const { getInitials } = useInitials();
const { t } = useTranslations();
const { openDirectMessage } = useOpenDirectMessage(() => props.teamSlug);

// Our own query drives the ranking; the Command's internal filter is left empty
// so it never hides a subsequence match the ranker surfaced (as in the quick
// switcher).
const query = ref('');

const people = computed(() =>
    rankPeople(props.members, query.value, props.currentUserId),
);

// Reset the query whenever the dialog closes so it always reopens blank.
watch(open, (isOpen) => {
    if (!isOpen) {
        query.value = '';
    }
});

function selectPerson(id: string): void {
    open.value = false;
    openDirectMessage(id);
}
</script>

<template>
    <CommandDialog
        v-model:open="open"
        :title="$t('New message')"
        :description="$t('Search for a person to message')"
    >
        <div class="flex h-12 items-center gap-2.5 border-b px-4">
            <Search class="size-4 shrink-0 text-muted-foreground/70" />
            <ListboxFilter
                v-model="query"
                auto-focus
                :placeholder="$t('Search people…')"
                data-test="new-dm-input"
                class="flex h-10 w-full rounded-md bg-transparent py-3 text-sm outline-hidden placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50"
            />
        </div>
        <CommandList>
            <CommandGroup v-if="people.length > 0" :heading="$t('People')">
                <CommandItem
                    v-for="person in people"
                    :key="person.id"
                    :value="`person:${person.id}`"
                    data-test="new-dm-person"
                    class="group h-[38px] gap-2 rounded-lg px-2.5 data-[highlighted]:bg-primary data-[highlighted]:text-primary-foreground"
                    @select="selectPerson(person.id)"
                >
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary select-none group-data-[highlighted]:bg-primary-foreground/20 group-data-[highlighted]:text-primary-foreground"
                        aria-hidden="true"
                        >{{ getInitials(person.name) }}</span
                    >
                    <span class="truncate">{{
                        person.isSelf ? t('You') : person.name
                    }}</span>
                    <span
                        class="ml-auto font-mono text-[11px] text-primary-foreground/70 opacity-0 group-data-[highlighted]:opacity-100"
                        aria-hidden="true"
                        >↵</span
                    >
                </CommandItem>
            </CommandGroup>
            <p
                v-else
                data-test="new-dm-empty"
                class="px-2 py-3 text-center text-xs text-muted-foreground"
            >
                {{ $t('No people match “:query”.', { query: query.trim() }) }}
            </p>
        </CommandList>
    </CommandDialog>
</template>
