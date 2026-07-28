<script setup lang="ts">
defineProps<{
    /** The commands to offer, already filtered against what has been typed. */
    commands: App.Data.SlashCommandData[];
    /** Which row the keyboard is on. */
    activeIndex: number;
}>();

defineEmits<{
    /** A command was chosen (clicked). */
    select: [command: App.Data.SlashCommandData];
    /** The pointer moved onto a row, which becomes the active one. */
    activate: [index: number];
}>();
</script>

<template>
    <!-- Mirrors the mention menu's listbox ARIA and keyboard model, but
         triggers only at composer position 0. Each row shows
         name · argument hint · description. -->
    <ul
        id="slash-listbox"
        data-test="slash-menu"
        role="listbox"
        :aria-label="$t('Slash commands')"
        class="absolute bottom-full left-0 z-10 mb-2 max-h-60 w-80 overflow-y-auto rounded-lg border border-border bg-popover p-1 shadow-md"
    >
        <li
            v-for="(command, index) in commands"
            :id="`slash-option-${index}`"
            :key="command.name"
            data-test="slash-option"
            role="option"
            tabindex="-1"
            :aria-selected="index === activeIndex"
            class="flex w-full cursor-pointer flex-col gap-0.5 rounded-md px-2 py-1.5 text-left text-sm text-popover-foreground"
            :class="
                index === activeIndex
                    ? 'bg-accent text-accent-foreground'
                    : 'hover:bg-accent/60'
            "
            @mousedown.prevent="$emit('select', command)"
            @mouseenter="$emit('activate', index)"
        >
            <span class="flex items-baseline gap-1.5">
                <span class="font-semibold">/{{ command.name }}</span>
                <span
                    v-if="command.argumentHint"
                    class="text-[11px] text-muted-foreground"
                >
                    {{ command.argumentHint }}
                </span>
            </span>
            <span class="truncate text-[12px] text-muted-foreground">
                {{ command.description }}
            </span>
        </li>
    </ul>
</template>
