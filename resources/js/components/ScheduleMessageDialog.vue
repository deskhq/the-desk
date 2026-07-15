<script setup lang="ts">
import { CalendarDate, today } from '@internationalized/date';
import { Check, Clock } from '@lucide/vue';
import type { DateValue } from 'reka-ui';
import { computed, ref, watch } from 'vue';
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
import { translate } from '@/lib/i18n';
import {
    formatScheduledFor,
    isSendAtInFuture,
    schedulePresets,
    to12Hour,
    to24Hour,
    wallTimeToInstant,
    zonedWallTime,
} from '@/lib/scheduleTime';
import type { SchedulePreset } from '@/lib/scheduleTime';

const props = withDefaults(
    defineProps<{
        // The viewer's stored IANA zone; falls back to the runtime zone when null
        // so presets and the picker are always resolved in a real zone.
        timezone: string | null;
        // When editing an existing scheduled message, the instant to open on.
        initialSendAt?: string | null;
        title?: string;
        confirmLabel?: string;
    }>(),
    {
        initialSendAt: null,
        title: translate('Schedule message'),
        confirmLabel: translate('Schedule'),
    },
);

const emit = defineEmits<{
    confirm: [sendAt: string];
}>();

const open = defineModel<boolean>('open', { default: false });

const effectiveZone = computed(
    () => props.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone,
);

const HOURS = Array.from({ length: 12 }, (_, index) => index + 1);
const MINUTES = Array.from({ length: 12 }, (_, index) => index * 5);
const PERIODS = ['AM', 'PM'] as const;

// The calendar + time selects are the single source of truth; a preset is just a
// shortcut that fills them in, so the "Sends…" preview always agrees with what
// the controls show.
const presets = ref<SchedulePreset[]>([]);
const dateValue = ref<DateValue>();
const minDate = ref<DateValue>();
const hour = ref(9);
const minute = ref(0);
const period = ref<'AM' | 'PM'>('AM');

/**
 * Point the controls at a UTC instant, expressed in the viewer's zone. Minutes
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

function reset(): void {
    presets.value = schedulePresets(effectiveZone.value);
    minDate.value = today(effectiveZone.value);
    // Seed from the edited instant, else from the first preset ("In 1 hour") so
    // it opens highlighted with a valid, future default.
    applyInstant(props.initialSendAt ?? presets.value[0].sendAt);
}

watch(open, (isOpen) => {
    if (isOpen) {
        reset();
    }
});

function choosePreset(preset: SchedulePreset): void {
    applyInstant(preset.sendAt);
}

// The chosen instant as a UTC ISO string, derived from the controls.
const selectedSendAt = computed<string | null>(() => {
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

// Highlight the preset whose (grid-snapped) time the controls currently match,
// so choosing one lights it up and nudging a select clears it.
const activePresetKey = computed(() => {
    const current = selectedSendAt.value;

    if (current === null) {
        return null;
    }

    const zone = effectiveZone.value;

    return (
        presets.value.find((preset) => {
            const wall = zonedWallTime(zone, new Date(preset.sendAt));

            return (
                wallTimeToInstant(
                    { ...wall, minute: Math.floor(wall.minute / 5) * 5 },
                    zone,
                ).toISOString() === current
            );
        })?.key ?? null
    );
});

const preview = computed(() =>
    selectedSendAt.value
        ? formatScheduledFor(selectedSendAt.value, effectiveZone.value)
        : null,
);

// The chosen time can still be in the past (an earlier time today), so guard the
// submit and surface why it's disabled. The server enforces the same rule.
const isFuture = computed(
    () =>
        selectedSendAt.value !== null && isSendAtInFuture(selectedSendAt.value),
);

function confirm(): void {
    if (!selectedSendAt.value || !isFuture.value) {
        return;
    }

    emit('confirm', selectedSendAt.value);
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[85dvh] gap-3 overflow-y-auto p-0 sm:max-w-md"
        >
            <DialogHeader class="gap-1 px-6 pt-6">
                <DialogTitle class="text-[20px]">{{ props.title }}</DialogTitle>
                <DialogDescription class="text-[12.5px]">
                    {{
                        $t('Times are in your timezone · :zone', {
                            zone: effectiveZone,
                        })
                    }}
                </DialogDescription>
            </DialogHeader>

            <div
                class="flex flex-wrap gap-1.5 px-6"
                data-test="schedule-presets"
            >
                <Button
                    v-for="preset in presets"
                    :key="preset.key"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="schedule-preset"
                    :data-preset="preset.key"
                    :aria-pressed="preset.key === activePresetKey"
                    class="inline-flex items-center gap-1.5 rounded-full border border-input bg-card px-3 py-1.5 text-[12.5px] font-medium text-muted-foreground hover:bg-muted aria-pressed:border-brass-border aria-pressed:bg-brass-fill aria-pressed:font-semibold aria-pressed:text-foreground"
                    @click="choosePreset(preset)"
                >
                    <Check
                        v-if="preset.key === activePresetKey"
                        class="size-3 shrink-0 text-brass"
                    />
                    <span class="truncate">{{ $t(preset.label) }}</span>
                </Button>
            </div>

            <div class="mx-6 rounded-xl border border-border bg-card">
                <Calendar
                    v-model="dateValue"
                    :min-value="minDate"
                    weekday-format="short"
                    data-test="schedule-calendar"
                    class="p-2"
                />
                <div
                    class="flex items-center gap-2 border-t border-border px-3 py-2.5"
                >
                    <Clock class="size-3.5 shrink-0 text-muted-foreground" />
                    <Select v-model="hour" data-test="schedule-hour">
                        <SelectTrigger
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
                    <span class="text-muted-foreground">:</span>
                    <Select v-model="minute" data-test="schedule-minute">
                        <SelectTrigger
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
                    <!-- AM/PM segmented toggle: the picker's single source of the
                         meridiem, mirroring the mockup's pill switch. -->
                    <div
                        data-test="schedule-period"
                        class="ml-auto flex items-center rounded-full bg-muted p-0.5"
                    >
                        <Button
                            v-for="value in PERIODS"
                            :key="value"
                            variant="segmented"
                            size="none"
                            type="button"
                            :data-period="value"
                            :aria-pressed="period === value"
                            class="h-6.5 px-3 text-[12px] font-semibold"
                            @click="period = value"
                        >
                            {{ value }}
                        </Button>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3 px-6 pb-6">
                <p
                    v-if="preview && isFuture"
                    data-test="schedule-preview"
                    class="flex items-center gap-1.5 text-[12.5px] font-medium text-brass-fill-foreground"
                >
                    <Clock class="size-3 shrink-0 text-brass" />
                    {{ $t('Sends :when', { when: preview }) }}
                </p>
                <p
                    v-else-if="preview"
                    data-test="schedule-past"
                    class="text-[12.5px] text-destructive"
                >
                    {{ $t('Pick a time in the future.') }}
                </p>

                <div class="ml-auto flex items-center gap-2">
                    <Button
                        variant="secondary"
                        class="rounded-full"
                        @click="open = false"
                    >
                        {{ $t('Cancel') }}
                    </Button>
                    <Button
                        data-test="schedule-confirm"
                        class="rounded-full"
                        :disabled="!isFuture"
                        @click="confirm"
                    >
                        {{ props.confirmLabel }}
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
