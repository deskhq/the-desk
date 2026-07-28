<script setup lang="ts">
import { Clock } from '@lucide/vue';
import type { DateValue } from 'reka-ui';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const HOURS = Array.from({ length: 12 }, (_, index) => index + 1);
const MINUTES = Array.from({ length: 12 }, (_, index) => index * 5);
const PERIODS = ['AM', 'PM'] as const;

defineProps<{
    /** The earliest day the calendar offers: the viewer's own today. */
    minDate: DateValue | undefined;
}>();

const dateValue = defineModel<DateValue | undefined>('date', {
    required: true,
});
const hour = defineModel<number>('hour', { required: true });
const minute = defineModel<number>('minute', { required: true });
const period = defineModel<'AM' | 'PM'>('period', { required: true });
</script>

<template>
    <!-- "Custom…" swaps in the same day + time controls the composer's
         "Send later" picker uses. -->
    <div
        data-test="status-custom-expiry"
        class="rounded-xl border border-border bg-card"
    >
        <Calendar
            v-model="dateValue"
            :min-value="minDate"
            weekday-format="short"
            class="p-2"
        />
        <div class="flex items-center gap-2 border-t border-border px-3 py-2.5">
            <Clock
                class="size-3.5 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <Select v-model="hour">
                <SelectTrigger
                    data-test="status-expiry-hour"
                    :aria-label="$t('Hour')"
                    class="h-8 gap-1.5 rounded-lg px-3 text-[13px] font-semibold"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="value in HOURS"
                        :key="value"
                        :value="value"
                    >
                        {{ value }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <span aria-hidden="true" class="text-muted-foreground">:</span>
            <Select v-model="minute">
                <SelectTrigger
                    data-test="status-expiry-minute"
                    :aria-label="$t('Minute')"
                    class="h-8 gap-1.5 rounded-lg px-3 text-[13px] font-semibold"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="value in MINUTES"
                        :key="value"
                        :value="value"
                    >
                        {{ String(value).padStart(2, '0') }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <div
                role="group"
                :aria-label="$t('AM or PM')"
                class="ml-auto flex items-center rounded-full bg-muted p-0.5"
            >
                <Button
                    v-for="value in PERIODS"
                    :key="value"
                    variant="segmented"
                    size="none"
                    type="button"
                    :aria-pressed="period === value"
                    class="h-6.5 px-3 text-[12px] font-semibold"
                    @click="period = value"
                >
                    {{ value }}
                </Button>
            </div>
        </div>
    </div>
</template>
