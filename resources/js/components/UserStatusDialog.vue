<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { CalendarDate, today } from '@internationalized/date';
import { Clock, Smile } from '@lucide/vue';
import type { DateValue } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    destroy as destroyStatus,
    update as updateStatus,
} from '@/actions/App/Http/Controllers/Settings/StatusController';
import EmojiPickerPopover from '@/components/EmojiPickerPopover.vue';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import UserStatusEmoji from '@/components/UserStatusEmoji.vue';
import { useTranslations } from '@/composables/useTranslations';
import {
    to12Hour,
    to24Hour,
    wallTimeToInstant,
    zonedWallTime,
} from '@/lib/scheduleTime';
import type { StatusExpiryKey } from '@/lib/statusExpiry';
import {
    resolveStatusExpiry,
    STATUS_EXPIRY_KEYS,
    statusExpiryLabel,
} from '@/lib/statusExpiry';

/** The longest a status text may be, mirroring the column and the request rule. */
const MAX_TEXT_LENGTH = 100;

/** The emoji a free-form status falls back to when the user picks none. */
const DEFAULT_EMOJI = '💬';

const open = defineModel<boolean>('open', { default: false });

const page = usePage();
const { t } = useTranslations();

const status = computed(() => page.props.auth.user.status ?? null);
const isEditing = computed(() => status.value !== null);

const effectiveZone = computed(
    () =>
        page.props.auth.user.timezone ??
        Intl.DateTimeFormat().resolvedOptions().timeZone,
);

/**
 * The built-in quick picks, each filling the emoji, the text, and its own
 * sensible default expiry in one tap — all still editable before saving.
 */
const presets = computed(() => [
    {
        key: 'meeting',
        emoji: '📅',
        text: t('In a meeting'),
        expiry: 'one-hour' as const,
    },
    {
        key: 'remote',
        emoji: '🏠',
        text: t('Working remotely'),
        expiry: 'today' as const,
    },
    { key: 'sick', emoji: '🤒', text: t('Out sick'), expiry: 'today' as const },
    {
        key: 'commuting',
        emoji: '🚌',
        text: t('Commuting'),
        expiry: 'thirty-minutes' as const,
    },
]);

const emoji = ref<string | null>(null);
const text = ref('');
const expiryKey = ref<StatusExpiryKey>('never');
const saving = ref(false);

// The custom date-and-time controls, live only while "Custom…" is chosen. They
// mirror the composer's schedule picker so both read the same in the same app.
const HOURS = Array.from({ length: 12 }, (_, index) => index + 1);
const MINUTES = Array.from({ length: 12 }, (_, index) => index * 5);
const PERIODS = ['AM', 'PM'] as const;

const dateValue = ref<DateValue>();
const minDate = ref<DateValue>();
const hour = ref(9);
const minute = ref(0);
const period = ref<'AM' | 'PM'>('AM');

/**
 * Point the custom controls at an instant, expressed in the viewer's zone.
 * Minutes snap down to the 5-minute grid the selects offer.
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
 * Seed the form from the viewer's current status each time the dialog opens, so
 * reopening after a cancel never shows a half-edited draft. An existing expiry
 * has no preset to map back to, so it opens on "Custom…" with the controls
 * pointed at it.
 */
function reset(): void {
    minDate.value = today(effectiveZone.value);
    emoji.value = status.value?.emoji ?? null;
    text.value = status.value?.text ?? '';
    expiryKey.value = status.value?.expiresAt ? 'custom' : 'never';
    applyInstant(status.value?.expiresAt ?? new Date().toISOString());
}

watch(open, (isOpen) => {
    if (isOpen) {
        reset();
    }
});

// The instant the custom controls name, or null before a day is picked.
const customExpiresAt = computed<string | null>(() => {
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

// The expiry to send: the custom controls' instant while "Custom…" is chosen,
// otherwise whatever the selected preset resolves to in the viewer's zone.
const expiresAt = computed<string | null>(() =>
    expiryKey.value === 'custom'
        ? customExpiresAt.value
        : resolveStatusExpiry(expiryKey.value, effectiveZone.value),
);

// A custom instant can sit in the past (an earlier time today); the server
// rejects it, so guard the submit and say why rather than round-tripping.
const isExpiryInFuture = computed(
    () =>
        expiresAt.value === null ||
        new Date(expiresAt.value).getTime() > Date.now(),
);

// A status needs *something* — an emoji or some text. Typing text without
// picking an emoji is the common case, so it saves under a neutral default
// rather than forcing a trip through the picker.
const effectiveEmoji = computed(
    () => emoji.value ?? (text.value.trim() === '' ? null : DEFAULT_EMOJI),
);

const canSave = computed(
    () =>
        effectiveEmoji.value !== null &&
        isExpiryInFuture.value &&
        !saving.value,
);

// What the emoji square previews, so it always shows what will be saved.
const previewStatus = computed<App.Data.UserStatusData | null>(() =>
    effectiveEmoji.value
        ? { emoji: effectiveEmoji.value, text: null, expiresAt: null }
        : null,
);

function choosePreset(preset: (typeof presets.value)[number]): void {
    emoji.value = preset.emoji;
    text.value = preset.text;
    expiryKey.value = preset.expiry;
}

function save(): void {
    if (!canSave.value || effectiveEmoji.value === null) {
        return;
    }

    saving.value = true;

    router.put(
        updateStatus().url,
        {
            emoji: effectiveEmoji.value,
            text: text.value.trim() || null,
            expires_at: expiresAt.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
            onError: () => toast.error(t('Could not save your status.')),
            onFinish: () => (saving.value = false),
        },
    );
}

function clear(): void {
    saving.value = true;

    router.delete(destroyStatus().url, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
        onError: () => toast.error(t('Could not clear your status.')),
        onFinish: () => (saving.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            data-test="status-dialog"
            class="max-h-[85dvh] gap-4 overflow-y-auto p-0 sm:max-w-md"
        >
            <DialogHeader class="gap-1 border-b border-border px-5 pt-5 pb-3.5">
                <DialogTitle class="font-serif text-[20px] tracking-[-0.01em]">
                    {{ isEditing ? $t('Edit status') : $t('Set a status') }}
                </DialogTitle>
                <DialogDescription class="sr-only">
                    {{
                        $t(
                            'Pick an emoji and a short message your teammates will see beside your name.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-4 px-5">
                <!-- Emoji square + free-form text + the remaining-characters
                     counter, as one focus-ringed field. -->
                <div
                    class="flex h-11 items-center gap-2.5 rounded-xl border border-input bg-card px-1.5 focus-within:border-brass-border focus-within:ring-[3px] focus-within:ring-brass/15"
                >
                    <EmojiPickerPopover
                        :tooltip="$t('Choose an emoji')"
                        @select="(picked) => (emoji = picked)"
                    >
                        <Button
                            variant="unstyled"
                            size="none"
                            type="button"
                            data-test="status-emoji-trigger"
                            :aria-label="$t('Choose an emoji')"
                            class="flex size-8.5 shrink-0 items-center justify-center rounded-[9px] bg-muted text-[17px] hover:bg-accent"
                        >
                            <UserStatusEmoji
                                v-if="previewStatus"
                                :status="previewStatus"
                                :name="page.props.auth.user.name"
                                decorative
                            />
                            <Smile
                                v-else
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </Button>
                    </EmojiPickerPopover>
                    <Input
                        v-model="text"
                        data-test="status-text-input"
                        :maxlength="MAX_TEXT_LENGTH"
                        :aria-label="$t('Status message')"
                        :placeholder="$t('What\'s your status?')"
                        class="h-9 flex-1 border-0 bg-transparent px-0 shadow-none focus-visible:border-0 focus-visible:ring-0"
                    />
                    <span
                        data-test="status-text-counter"
                        aria-hidden="true"
                        class="pr-2 font-mono text-[11px] text-muted-foreground"
                        >{{ text.length }}/{{ MAX_TEXT_LENGTH }}</span
                    >
                </div>

                <!-- Quick picks, offered when there is nothing set yet: one tap
                     fills emoji, text, and a sensible default expiry. -->
                <div v-if="!isEditing" class="flex flex-col gap-1.75">
                    <span
                        id="status-presets-label"
                        class="text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground uppercase"
                        >{{ $t('Quick picks') }}</span
                    >
                    <!-- A set of buttons, not a list: a `role="listitem"` on a
                         <button> replaces its button role, so the group is named
                         by the heading above it instead. -->
                    <div
                        role="group"
                        aria-labelledby="status-presets-label"
                        class="flex flex-col gap-px"
                    >
                        <Button
                            v-for="preset in presets"
                            :key="preset.key"
                            variant="unstyled"
                            size="none"
                            type="button"
                            data-test="status-preset"
                            :data-preset="preset.key"
                            class="flex h-8.5 items-center gap-2.5 rounded-[9px] px-2 text-[13.5px] text-foreground hover:bg-muted"
                            @click="choosePreset(preset)"
                        >
                            <span aria-hidden="true" class="text-[15px]">{{
                                preset.emoji
                            }}</span>
                            <span class="truncate">{{ preset.text }}</span>
                            <span
                                class="ml-auto font-serif text-[11.5px] text-muted-foreground italic"
                                >{{ statusExpiryLabel(preset.expiry) }}</span
                            >
                        </Button>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <span
                        id="status-expiry-label"
                        class="shrink-0 text-[13px] font-semibold text-muted-foreground"
                        >{{ $t('Clear after') }}</span
                    >
                    <Select v-model="expiryKey">
                        <SelectTrigger
                            data-test="status-expiry"
                            aria-labelledby="status-expiry-label"
                            class="h-9 flex-1 rounded-[10px] text-[13.5px]"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="key in STATUS_EXPIRY_KEYS"
                                :key="key"
                                :value="key"
                            >
                                {{ statusExpiryLabel(key) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!-- "Custom…" swaps in the same day + time controls the composer's
                     "Send later" picker uses. -->
                <div
                    v-if="expiryKey === 'custom'"
                    data-test="status-custom-expiry"
                    class="rounded-xl border border-border bg-card"
                >
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
                        <span aria-hidden="true" class="text-muted-foreground"
                            >:</span
                        >
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
                <p
                    v-if="!isExpiryInFuture"
                    data-test="status-expiry-past"
                    class="text-[12.5px] text-destructive-text"
                >
                    {{ $t('Pick a time in the future.') }}
                </p>
            </div>

            <div class="flex items-center gap-2 px-5 pb-5">
                <Button
                    v-if="isEditing"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="status-clear"
                    :disabled="saving"
                    class="inline-flex h-8.5 items-center rounded-full border border-border px-4 text-[12.5px] font-medium text-destructive-text hover:bg-destructive/10"
                    @click="clear"
                >
                    {{ $t('Clear status') }}
                </Button>
                <span class="flex-1" />
                <Button
                    variant="secondary"
                    class="h-8.5 rounded-full text-[12.5px]"
                    @click="open = false"
                >
                    {{ $t('Cancel') }}
                </Button>
                <Button
                    data-test="status-save"
                    class="h-8.5 rounded-full text-[12.5px]"
                    :disabled="!canSave"
                    @click="save"
                >
                    {{ $t('Save status') }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
