<script setup lang="ts" generic="T">
import {
    autocompleteListboxId,
    autocompleteOptionId,
} from '@/composables/useAutocompleteMenu';
import type { AutocompleteMenu } from '@/composables/useAutocompleteMenu';

const props = defineProps<{
    /** The menu being rendered, which the rows drive directly. */
    menu: AutocompleteMenu<T>;
    /** What the listbox is called, announced when it opens. */
    label: string;
    /** A stable key per row; the two menus key off different fields. */
    keyOf: (item: T) => string;
    /** How wide the panel sits: one-line rows are narrow, two-line ones wide. */
    width?: 'narrow' | 'wide';
}>();

function optionId(index: number): string {
    return autocompleteOptionId(props.menu.name, index);
}
</script>

<template>
    <!-- The listbox both composer autocompletes render: one ARIA contract, one
         set of row states, one pointer model. What a row *shows* is the
         caller's, through the `option` slot; everything around it is here. -->
    <ul
        :id="autocompleteListboxId(menu.name)"
        :data-test="`${menu.name}-menu`"
        role="listbox"
        :aria-label="label"
        class="absolute bottom-full left-0 z-10 mb-2 max-h-60 overflow-y-auto rounded-lg border border-border bg-popover p-1 shadow-md"
        :class="width === 'wide' ? 'w-80' : 'w-64'"
    >
        <li
            v-for="(item, index) in menu.suggestions.value"
            :id="optionId(index)"
            :key="keyOf(item)"
            :data-test="`${menu.name}-option`"
            role="option"
            tabindex="-1"
            :aria-selected="index === menu.activeIndex.value"
            class="w-full cursor-pointer rounded-md px-2 py-1.5 text-left text-sm text-popover-foreground"
            :class="
                index === menu.activeIndex.value
                    ? 'bg-accent text-accent-foreground'
                    : 'hover:bg-accent/60'
            "
            @mousedown.prevent="menu.select(item)"
            @mouseenter="menu.setActive(index)"
        >
            <slot name="option" :item="item" :index="index" />
        </li>
        <slot name="footer" />
    </ul>
</template>
