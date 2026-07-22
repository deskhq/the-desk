<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { CalendarDate, today } from '@internationalized/date';
import { Clock } from '@lucide/vue';
import type { DateValue } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { update as updateDndPause } from '@/actions/App/Http/Controllers/Settings/DndController';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslations } from '@/composables/useTranslations';
import {
    to12Hour,
    to24Hour,
    wallTimeToInstant,
    zonedWallTime,
} from '@/lib/scheduleTime';

const open = defineModel<boolean>('open', { default: false });

const page = usePage();
const { t } = useTranslations();

const effectiveZone = computed(
    () =>
        page.props.auth.user.timezone ??
        Intl.DateTimeFormat().resolvedOptions().timeZone,
);

// The day + time controls mirror the status dialog's "Custom…" expiry (and the
// composer's schedule picker), so every until-picker reads the same in the app.
const HOURS = Array.from({ length: 12 }, (_, index) => index + 1);
const MINUTES = Array.from({ length: 12 }, (_, index) => index * 5);
const PERIODS = ['AM', 'PM'] as const;

const dateValue = ref<DateValue>();
const minDate = ref<DateValue>();
const hour = ref(9);
const minute = ref(0);
const period = ref<'AM' | 'PM'>('AM');
const saving = ref(false);

/** The grid the minute select offers, in milliseconds. */
const MINUTE_STEP_MS = 5 * 60_000;

/**
 * Point the controls at an instant, expressed in the viewer's zone. Minutes
 * snap down to the 5-minute grid the selects offer.
 */
function applyInstant(iso: string): void {
    const wall = zonedWallTime(effectiveZone.value, new Date(iso));
    dateValue.value = new CalendarDate(wall.year, wall.month, wall.day);
    const clock = to12Hour(wall.hour);
    hour.value = clock.hour;
    period.value = clock.period;
    minute.value = Math.floor(wall.minute / 5) * 5;
}

/**
 * The instant a fresh dialog opens on: a whole step ahead of now, snapped up
 * onto the grid, so it never opens already invalid. A pause still running
 * seeds the controls with its own lapse instead, ready to be extended — but a
 * pause that lapsed after these props were built would seed a past instant
 * with Save already disabled, so a stale one falls back to the default.
 */
function reset(): void {
    minDate.value = today(effectiveZone.value);

    const stepAhead = Date.now() + MINUTE_STEP_MS;
    const defaultUntil = new Date(
        Math.ceil(stepAhead / MINUTE_STEP_MS) * MINUTE_STEP_MS,
    ).toISOString();
    const runningUntil = page.props.auth.user.dnd?.until;

    applyInstant(
        runningUntil && new Date(runningUntil).getTime() > Date.now()
            ? runningUntil
            : defaultUntil,
    );
}

watch(open, (isOpen) => {
    if (isOpen) {
        reset();
    }
});

// The instant the controls name, or null before a day is picked.
const until = computed<string | null>(() => {
    if (!dateValue.value) {
        return null;
    }

    return wallTimeToInstant(
        {
            year: dateValue.value.year,
            month: dateValue.value.month,
            day: dateValue.value.day,
            hour: to24Hour(hour.value, period.value),
            minute: minute.value,
        },
        effectiveZone.value,
    ).toISOString();
});

// A picked instant can sit in the past (an earlier time today); the server
// rejects it, so guard the submit and say why rather than round-tripping.
const isUntilInFuture = computed(
    () => until.value !== null && new Date(until.value).getTime() > Date.now(),
);

const canSave = computed(() => isUntilInFuture.value && !saving.value);

function save(): void {
    if (!canSave.value || until.value === null) {
        return;
    }

    saving.value = true;

    router.put(
        updateDndPause().url,
        { until: until.value },
        {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
            onError: () =>
                toast.error(t('Could not pause your notifications.')),
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            data-test="dnd-pause-dialog"
            class="max-h-[85dvh] gap-4 overflow-y-auto p-0 sm:max-w-md"
        >
            <DialogHeader class="gap-1 border-b border-border px-5 pt-5 pb-3.5">
                <DialogTitle class="font-serif text-[20px] tracking-[-0.01em]">
                    {{ $t('Pause notifications') }}
                </DialogTitle>
                <DialogDescription class="sr-only">
                    {{
                        $t(
                            'Pick when the chime comes back. Teammates only see that notifications are paused.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-4 px-5">
                <div class="rounded-xl border border-border bg-card">
                    <Calendar
                        v-model="dateValue"
                        :min-value="minDate"
                        weekday-format="short"
                        class="p-2"
                    />
                    <div
                        class="flex items-center gap-2 border-t border-border px-3 py-2.5"
                    >
                        <Clock
                            class="size-3.5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Select v-model="hour">
                            <SelectTrigger
                                data-test="dnd-pause-hour"
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
                        <span aria-hidden="true" class="text-muted-foreground"
                            >:</span
                        >
                        <Select v-model="minute">
                            <SelectTrigger
                                data-test="dnd-pause-minute"
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
                <p
                    v-if="!isUntilInFuture && until !== null"
                    data-test="dnd-pause-past"
                    class="text-[12.5px] text-destructive-text"
                >
                    {{ $t('Pick a time in the future.') }}
                </p>
            </div>

            <div class="flex items-center gap-2 px-5 pb-5">
                <span class="flex-1" />
                <Button
                    variant="secondary"
                    class="h-8.5 rounded-full text-[12.5px]"
                    @click="open = false"
                >
                    {{ $t('Cancel') }}
                </Button>
                <Button
                    data-test="dnd-pause-save"
                    class="h-8.5 rounded-full text-[12.5px]"
                    :disabled="!canSave"
                    @click="save"
                >
                    {{ $t('Pause notifications') }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
