<script setup lang="ts">
import AutocompleteListbox from '@/components/composer/AutocompleteListbox.vue';
import type { AutocompleteMenu } from '@/composables/useAutocompleteMenu';

defineProps<{
    /** The `/` menu, offering the commands matching what has been typed. */
    menu: AutocompleteMenu<App.Data.SlashCommandData>;
}>();

function keyOf(command: App.Data.SlashCommandData): string {
    return command.name;
}
</script>

<template>
    <!-- The same listbox the mention menu renders, triggering only at composer
         position 0. Each row shows name · argument hint · description, which is
         what it is the wider of the two for. -->
    <AutocompleteListbox
        :menu="menu"
        :label="$t('Slash commands')"
        :key-of="keyOf"
        width="wide"
    >
        <template #option="{ item }">
            <div class="flex flex-col gap-0.5">
                <span class="flex items-baseline gap-1.5">
                    <span class="font-semibold">/{{ item.name }}</span>
                    <span
                        v-if="item.argumentHint"
                        class="text-[11px] text-muted-foreground"
                    >
                        {{ item.argumentHint }}
                    </span>
                </span>
                <span class="truncate text-[12px] text-muted-foreground">
                    {{ item.description }}
                </span>
            </div>
        </template>
    </AutocompleteListbox>
</template>
