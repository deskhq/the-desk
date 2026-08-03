<script setup lang="ts">
import SwitcherName from '@/components/switcher/SwitcherName.vue';
import { CommandGroup, CommandItem } from '@/components/ui/command';
import type { PaletteCommand } from '@/composables/commands';

defineProps<{
    /** The ranked verbs, in the order they render. */
    commands: PaletteCommand[];
    /** The raw query, whose match each mobile row brightens. */
    query: string;
    /** Whether the palette is rendering as the mobile full-screen overlay. */
    isMobile: boolean;
}>();

defineEmits<{
    /** The viewer picked a verb; the palette dismisses and runs it. */
    select: [command: PaletteCommand];
}>();
</script>

<template>
    <CommandGroup v-if="commands.length > 0" :heading="$t('Commands')">
        <CommandItem
            v-for="command in commands"
            :key="command.id"
            :value="`command:${command.id}`"
            :data-test="`quick-switcher-${command.id}`"
            class="group h-9.5 gap-2 rounded-lg px-2.5 max-md:h-11.5 max-md:gap-2.5 max-md:rounded-[11px] max-md:px-3 md:data-[highlighted]:bg-primary md:data-[highlighted]:text-primary-foreground"
            @select="$emit('select', command)"
        >
            <component
                :is="command.icon"
                class="size-4 shrink-0 text-muted-foreground/70 group-data-[highlighted]:text-brass"
                aria-hidden="true"
            />
            <SwitcherName
                :name="command.title"
                :query="query"
                :highlight="isMobile"
            />
            <!-- A row that claims a shortcut wears its keys where the other rows
                 draw their enter hint, so the palette and the shortcuts modal
                 reference each other in the list rather than in prose. -->
            <span
                v-if="command.keys.length > 0"
                class="ml-auto flex shrink-0 items-center gap-1"
                aria-hidden="true"
            >
                <kbd
                    v-for="key in command.keys"
                    :key="key"
                    class="flex h-5 min-w-5 items-center justify-center rounded border border-border bg-muted px-1 font-mono text-[10px] font-medium text-muted-foreground md:group-data-[highlighted]:border-primary-foreground/30 md:group-data-[highlighted]:bg-primary-foreground/15 md:group-data-[highlighted]:text-primary-foreground"
                    >{{ key }}</kbd
                >
            </span>
            <span
                v-else
                class="ml-auto font-mono text-[11px] text-primary-foreground/70 opacity-0 group-data-[highlighted]:opacity-100 max-md:hidden"
                aria-hidden="true"
                >↵</span
            >
        </CommandItem>
    </CommandGroup>
</template>
