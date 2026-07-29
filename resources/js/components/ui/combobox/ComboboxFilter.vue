<script setup lang="ts">
import { injectListboxRootContext, ListboxFilter } from 'reka-ui';
import { watch } from 'vue';
import { useCommand } from '@/components/ui/command';

/**
 * The combobox's text field. It writes straight into the surrounding
 * `<Command>`'s filter state, so the listbox below narrows as it is typed, and
 * reka's `ListboxFilter` keeps the roving `aria-activedescendant` pointed at
 * the highlighted option while the arrow keys move through the list.
 */
const props = defineProps<{
    /**
     * Accessible name for the field. Required: the popup carries no visible
     * label, so this is the only thing that says which facet is being filtered.
     */
    fieldLabel: string;
    /** Id of the listbox this field controls, for `aria-controls`. */
    listboxId: string;
    placeholder: string;
}>();

const { filterState } = useCommand();

const listbox = injectListboxRootContext();

/**
 * reka keeps the roving highlight on the option it last pointed at, even once
 * the filter has taken that option out of the DOM — leaving
 * `aria-activedescendant` naming an element that no longer exists, which axe
 * reports as a critical `aria-valid-attr-value` violation. Re-checking once the
 * list has re-rendered drops such a highlight; reka highlights the first
 * surviving option by itself, so this only bites when nothing matched at all.
 */
watch(
    () => filterState.search,
    () => {
        const highlighted = listbox.highlightedElement.value;

        if (highlighted !== null && !highlighted.isConnected) {
            listbox.highlightedElement.value = null;
        }
    },
    { flush: 'post' },
);
</script>

<template>
    <!--
        The field only ever renders inside an open popup, so the list it
        controls is showing whenever the field exists: `aria-expanded` is true
        by construction rather than tracking a second piece of state.
    -->
    <ListboxFilter
        v-model="filterState.search"
        auto-focus
        role="combobox"
        aria-autocomplete="list"
        aria-expanded="true"
        :aria-controls="props.listboxId"
        :aria-label="props.fieldLabel"
        :placeholder="props.placeholder"
        data-slot="combobox-filter"
        class="border-input placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground focus-visible:border-ring focus-visible:ring-ring/50 dark:bg-input/30 mb-1 h-8 w-full min-w-0 rounded-md border bg-transparent px-2.5 py-1 text-base shadow-xs outline-none focus-visible:ring-[3px] max-md:h-11 md:text-xs"
    />
</template>
