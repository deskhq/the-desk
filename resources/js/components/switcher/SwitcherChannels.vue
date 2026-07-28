<script setup lang="ts">
import SwitcherName from '@/components/switcher/SwitcherName.vue';
import { CommandGroup, CommandItem } from '@/components/ui/command';
import type { Channel } from '@/types/channels';

defineProps<{
    /** The ranked channels, in the order they render. */
    channels: Channel[];
    /** The raw query, whose match each mobile row brightens. */
    query: string;
    /** Whether the palette is rendering as the mobile full-screen overlay. */
    isMobile: boolean;
}>();

defineEmits<{
    /** The viewer picked a channel; the palette navigates into it. */
    select: [channel: Channel];
}>();
</script>

<template>
    <CommandGroup v-if="channels.length > 0" :heading="$t('Channels')">
        <CommandItem
            v-for="channel in channels"
            :key="channel.id"
            :value="`channel:${channel.id}`"
            data-test="quick-switcher-channel"
            class="group h-9.5 gap-2 rounded-lg px-2.5 max-md:h-11.5 max-md:gap-2.5 max-md:rounded-[11px] max-md:px-3 md:data-[highlighted]:bg-primary md:data-[highlighted]:text-primary-foreground"
            @select="$emit('select', channel)"
        >
            <span
                class="shrink-0 font-semibold text-muted-foreground group-data-[highlighted]:text-brass max-md:font-serif max-md:text-[17px] max-md:italic"
                aria-hidden="true"
                >#</span
            >
            <SwitcherName
                :name="channel.name"
                :query="query"
                :highlight="isMobile"
            />
            <span
                class="ml-auto font-mono text-[11px] text-primary-foreground/70 opacity-0 group-data-[highlighted]:opacity-100 max-md:hidden"
                aria-hidden="true"
                >↵</span
            >
        </CommandItem>
    </CommandGroup>
</template>
