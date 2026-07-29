<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { useId } from 'vue';
import {
    Command,
    CommandEmpty,
    CommandList,
    provideCommandGroupContext,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import ComboboxFilter from './ComboboxFilter.vue';
import { provideComboboxContext } from '.';

/**
 * A filterable single-select popup built as a real combobox: a `combobox` text
 * field that types-to-filter, beside — never inside — a `listbox` of `option`s
 * the arrow keys rove through. Its trigger is a slot, so a call site keeps
 * whatever affordance it already draws (a facet pill, a field, a button).
 */
const props = defineProps<{
    /**
     * Accessible name for the filter field and the popup around it. Required:
     * the popup shows no visible label, so this is the only thing that says
     * which list is being filtered.
     */
    fieldLabel: string;
    /** Accessible name for the listbox, saying what the options are. */
    listLabel: string;
    /** Placeholder for the filter field. */
    placeholder: string;
    /** Shown in place of the list once the filter matches nothing. */
    emptyText: string;
    /** Extra classes for the popup surface, e.g. its width. */
    class?: HTMLAttributes['class'];
}>();

defineOptions({
    // The trigger is the call site's own element, so fallthrough attributes go
    // to the one element this component owns and a caller may need to reach:
    // the filter field.
    inheritAttrs: false,
});

const open = defineModel<boolean>('open', { default: false });

const listboxId = useId();

provideComboboxContext({
    close: () => {
        open.value = false;
    },
});

/**
 * A single flat list needs no `<CommandGroup>` — and rendering one would leave
 * a `group` whose `aria-labelledby` points at a heading that was never drawn.
 * Standing in for it keeps `<CommandItem>`'s required group context satisfied
 * while the listbox owns its options directly.
 */
provideCommandGroupContext({});
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <slot name="trigger" />
        </PopoverTrigger>
        <PopoverContent
            align="start"
            :aria-label="props.fieldLabel"
            :class="cn('w-56 p-1.5', props.class)"
        >
            <Command>
                <ComboboxFilter
                    :field-label="props.fieldLabel"
                    :listbox-id="listboxId"
                    :placeholder="props.placeholder"
                    v-bind="$attrs"
                />
                <CommandList
                    :id="listboxId"
                    :ariaLabel="props.listLabel"
                    class="max-h-56"
                >
                    <slot />
                </CommandList>
                <CommandEmpty class="py-3 text-[13px] text-muted-foreground">
                    {{ props.emptyText }}
                </CommandEmpty>
            </Command>
        </PopoverContent>
    </Popover>
</template>
