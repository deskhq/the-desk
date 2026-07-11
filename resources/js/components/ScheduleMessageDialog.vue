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
    DialogFooter,
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
        <DialogContent class="max-h-[85dvh] gap-3 overflow-y-auto sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ props.title }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'Pick when this message should be sent. Times are in your timezone.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="grid grid-cols-2 gap-2" data-test="schedule-presets">
                <button
                    v-for="preset in presets"
                    :key="preset.key"
                    type="button"
                    data-test="schedule-preset"
                    :data-preset="preset.key"
                    :aria-pressed="preset.key === activePresetKey"
                    class="flex items-center justify-between gap-2 rounded-md border px-3 py-1.5 text-left text-[13px] transition-colors"
                    :class="
                        preset.key === activePresetKey
                            ? 'border-primary bg-primary/5 font-medium text-foreground'
                            : 'border-input hover:bg-muted'
                    "
                    @click="choosePreset(preset)"
                >
                    <span class="truncate">{{ $t(preset.label) }}</span>
                    <Check
                        v-if="preset.key === activePresetKey"
                        class="size-4 shrink-0 text-primary"
                    />
                </button>
            </div>

            <div class="rounded-lg border border-border">
                <p
                    class="border-b border-border px-3 py-1.5 text-[12px] font-medium text-muted-foreground"
                >
                    {{ $t('Or pick a date & time') }}
                </p>
                <Calendar
                    v-model="dateValue"
                    :min-value="minDate"
                    weekday-format="short"
                    data-test="schedule-calendar"
                    class="p-2"
                />
                <div
                    class="flex items-center gap-2 border-t border-border px-3 py-2"
                >
                    <Clock class="size-4 shrink-0 text-muted-foreground" />
                    <Select v-model="hour" data-test="schedule-hour">
                        <SelectTrigger class="h-8 w-20">
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
                        <SelectTrigger class="h-8 w-20">
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
                    <Select v-model="period" data-test="schedule-period">
                        <SelectTrigger class="h-8 w-20">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="value in PERIODS"
                                :key="value"
                                :value="value"
                            >
                                {{ value }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <p
                v-if="preview && isFuture"
                data-test="schedule-preview"
                class="flex items-center gap-1.5 text-[13px] text-muted-foreground"
            >
                <Clock class="size-3.5 shrink-0" />
                {{ $t('Sends :when', { when: preview }) }}
            </p>
            <p
                v-else-if="preview"
                data-test="schedule-past"
                class="text-[13px] text-destructive"
            >
                {{ $t('Pick a time in the future.') }}
            </p>

            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="open = false">
                    {{ $t('Cancel') }}
                </Button>
                <Button
                    data-test="schedule-confirm"
                    :disabled="!isFuture"
                    @click="confirm"
                >
                    {{ props.confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
