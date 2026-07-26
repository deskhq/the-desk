<script setup lang="ts">
import type { ListboxItemEmits, ListboxItemProps } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { reactiveOmit } from '@vueuse/core';
import { CommandItem } from '@/components/ui/command';
import { cn } from '@/lib/utils';
import { useCombobox } from '.';

/**
 * One `role="option"` row of a `<Combobox>`. Selecting it — by click, by Enter
 * on the highlighted row — reports the choice and dismisses the popup, so a
 * call site never has to drive the open state itself.
 */
const props = defineProps<
    ListboxItemProps & { class?: HTMLAttributes['class'] }
>();

const emits = defineEmits<ListboxItemEmits>();

const delegatedProps = reactiveOmit(props, 'class');

const { close } = useCombobox();

/** The payload reka hands a `select` listener: the choice, and the event behind it. */
type SelectEvent = ListboxItemEmits['select'][0];

function select(event: SelectEvent): void {
    emits('select', event);
    close();
}
</script>

<template>
    <CommandItem
        v-bind="delegatedProps"
        :class="
            cn(
                'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[13px] max-md:min-h-11',
                props.class,
            )
        "
        @select="select"
    >
        <slot />
    </CommandItem>
</template>
