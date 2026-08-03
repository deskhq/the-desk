<script setup lang="ts">
/** PROTOTYPE — throwaway. A command rendered as a grouped row (variants A, B). */
import { CommandItem } from '@/components/ui/command';
import type { PrototypeCommand } from './paletteCommands';

defineProps<{
    command: PrototypeCommand;
    isMobile: boolean;
    /** Whether the row shows the shortcut keys it inherits, when it has any. */
    showKeys: boolean;
}>();

defineEmits<{ select: [command: PrototypeCommand] }>();
</script>

<template>
    <CommandItem
        :value="`command:${command.id}`"
        data-test="palette-command"
        class="group h-9.5 gap-2 rounded-lg px-2.5 max-md:h-11.5 max-md:gap-2.5 max-md:rounded-[11px] max-md:px-3 max-md:text-[15px] md:data-[highlighted]:bg-primary md:data-[highlighted]:text-primary-foreground"
        @select="$emit('select', command)"
    >
        <component
            :is="command.icon"
            class="size-4 shrink-0 text-muted-foreground/70 group-data-[highlighted]:text-brass"
        />
        <span class="truncate">{{ $t(command.title) }}</span>
        <span
            v-if="showKeys && command.keys"
            class="ml-auto flex shrink-0 gap-1"
            aria-hidden="true"
        >
            <kbd
                v-for="key in command.keys"
                :key="key"
                class="rounded border border-border bg-muted px-1.5 font-mono text-[10px] text-muted-foreground group-data-[highlighted]:border-primary-foreground/30 group-data-[highlighted]:bg-primary-foreground/10 group-data-[highlighted]:text-primary-foreground"
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
</template>
