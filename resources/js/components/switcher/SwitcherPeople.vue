<script setup lang="ts">
import PresenceDot from '@/components/PresenceDot.vue';
import SwitcherName from '@/components/switcher/SwitcherName.vue';
import { CommandGroup, CommandItem } from '@/components/ui/command';
import { getInitials } from '@/composables/useInitials';
import { useTeamPresence } from '@/composables/useTeamPresence';
import { useTranslations } from '@/composables/useTranslations';
import { presenceLabelKey } from '@/lib/presence';
import type { PersonEntry } from '@/types/people';

defineProps<{
    /** The ranked team members, in the order they render. */
    people: PersonEntry[];
    /** The raw query, whose match each mobile row brightens. */
    query: string;
    /** Whether the palette is rendering as the mobile full-screen overlay. */
    isMobile: boolean;
}>();

defineEmits<{
    /** The viewer picked a person; the palette opens/creates their DM. */
    select: [userId: string];
}>();

const { t } = useTranslations();

const { presenceFor, isDndFor } = useTeamPresence();
</script>

<template>
    <CommandGroup v-if="people.length > 0" :heading="$t('People')">
        <CommandItem
            v-for="person in people"
            :key="person.id"
            :value="`person:${person.id}`"
            data-test="quick-switcher-person"
            class="group h-9.5 gap-2 rounded-lg px-2.5 max-md:h-11.5 max-md:gap-2.5 max-md:rounded-[11px] max-md:px-3 md:data-[highlighted]:bg-primary md:data-[highlighted]:text-primary-foreground"
            @select="$emit('select', person.id)"
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
                    :is-dnd="isDndFor(person.id)"
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
            <SwitcherName
                :name="person.isSelf ? t('You') : person.name"
                :query="query"
                :highlight="isMobile"
            />
            <span
                v-if="isMobile"
                class="ml-auto shrink-0 text-[11.5px] text-muted-foreground"
                >{{ $t(presenceLabelKey(presenceFor(person.id))) }}</span
            >
            <span
                v-else
                class="ml-auto font-mono text-[11px] text-primary-foreground/70 opacity-0 group-data-[highlighted]:opacity-100"
                aria-hidden="true"
                >↵</span
            >
        </CommandItem>
    </CommandGroup>
</template>
