<script setup lang="ts">
import type { DateValue } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { Calendar as CalendarIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { toCalendarDate, toIsoDay } from '@/lib/calendarDate';
import { formatIsoDay } from '@/lib/datetime';
import { i18n } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * A calendar-backed replacement for `<input type="date">`. Its model is a plain
 * ISO `YYYY-MM-DD` day (or `null`), so a call site keeps the exact string it
 * already sends to the server, while the reader gets a themed, locale-aware
 * calendar instead of the browser's native picker.
 */
const props = withDefaults(
    defineProps<{
        /** The selected day as `YYYY-MM-DD`, or `null` when nothing is picked. */
        modelValue?: string | null;
        /** Trigger label shown while no day is selected. */
        placeholder?: string;
        /** Earliest selectable day, as `YYYY-MM-DD`. */
        min?: string | null;
        /** Latest selectable day, as `YYYY-MM-DD`. */
        max?: string | null;
        /** Accessible name for the trigger, since the value alone rarely says which field this is. */
        ariaLabel?: string;
        /** Marks the trigger `aria-invalid`, e.g. when the range it belongs to is backwards. */
        invalid?: boolean;
        disabled?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    {
        modelValue: null,
        placeholder: undefined,
        min: null,
        max: null,
        ariaLabel: undefined,
        invalid: false,
        disabled: false,
        class: undefined,
    },
);

const emits = defineEmits<{
    'update:modelValue': [day: string | null];
}>();

defineOptions({
    inheritAttrs: false,
});

const open = ref(false);

const selected = computed<DateValue | undefined>(() =>
    toCalendarDate(props.modelValue),
);

const label = computed(() =>
    props.modelValue ? formatIsoDay(props.modelValue) : props.placeholder,
);

function select(value: DateValue | undefined): void {
    emits('update:modelValue', toIsoDay(value));
    open.value = false;
}
</script>

<template>
    <Popover v-model:open="open">
            <PopoverTrigger as-child>
                <Button
                    variant="outline"
                    type="button"
                    :disabled="disabled"
                    :aria-label="ariaLabel"
                    :aria-invalid="invalid"
                    data-slot="date-picker-trigger"
                    :class="
                        cn(
                            'h-9 justify-start gap-2 rounded-lg px-3 font-normal',
                            modelValue ? undefined : 'text-muted-foreground',
                            props.class,
                        )
                    "
                    v-bind="$attrs"
                >
                    <CalendarIcon class="size-3.5" aria-hidden="true" />
                    {{ label }}
                </Button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-0" align="start">
                <Calendar
                    :model-value="selected"
                    :min-value="toCalendarDate(min)"
                    :max-value="toCalendarDate(max)"
                    :locale="i18n.locale"
                    weekday-format="short"
                    initial-focus
                    @update:model-value="select"
                />
            </PopoverContent>
    </Popover>
</template>
