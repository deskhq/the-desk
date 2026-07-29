import { router, usePage } from '@inertiajs/vue3';
import { CalendarDate, today } from '@internationalized/date';
import type { DateValue } from 'reka-ui';
import type { ComputedRef, Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import {
    destroy as destroyStatus,
    update as updateStatus,
} from '@/actions/App/Http/Controllers/Settings/StatusController';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import {
    to12Hour,
    to24Hour,
    wallTimeToInstant,
    zonedWallTime,
} from '@/lib/scheduleTime';
import type { StatusExpiryKey } from '@/lib/statusExpiry';
import { resolveStatusExpiry } from '@/lib/statusExpiry';

/** One of the built-in quick picks the dialog offers. */
export type StatusPreset = {
    key: string;
    emoji: string;
    text: string;
    expiry: StatusExpiryKey;
};

/** The emoji a free-form status falls back to when the user picks none. */
const DEFAULT_EMOJI = '💬';

/** The grid the minute select offers, in milliseconds. */
const MINUTE_STEP_MS = 5 * 60_000;

/**
 * The draft behind the "Set a status" dialog: what it is seeded with each time
 * it opens, the expiry its controls resolve to, and the two writes it makes.
 *
 * Takes the dialog's own open-state so it can seed itself on the way open and
 * close on a successful write — the same switch the dialog binds its overlay to.
 */
export function useUserStatusForm(open: Ref<boolean>) {
    const page = usePage();
    const { t } = useTranslations();
    const toast = useToast();

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
    const presets = computed<StatusPreset[]>(() => [
        {
            key: 'meeting',
            emoji: '📅',
            text: t('In a meeting'),
            expiry: 'one-hour',
        },
        {
            key: 'remote',
            emoji: '🏠',
            text: t('Working remotely'),
            expiry: 'today',
        },
        { key: 'sick', emoji: '🤒', text: t('Out sick'), expiry: 'today' },
        {
            key: 'commuting',
            emoji: '🚌',
            text: t('Commuting'),
            expiry: 'thirty-minutes',
        },
    ]);

    const emoji = ref<string | null>(null);
    const text = ref('');
    const expiryKey = ref<StatusExpiryKey>('never');
    const saving = ref(false);

    // The custom date-and-time controls, live only while "Custom…" is chosen.
    // They mirror the composer's schedule picker so both read the same in the
    // same app.
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
     * The instant a fresh "Custom…" opens on: a whole step ahead of now, snapped
     * up onto the grid. Seeding from the bare current time would snap *down* off
     * the grid and land in the past, so choosing "Custom…" would open straight
     * into "Pick a time in the future." with Save already disabled.
     */
    function defaultCustomInstant(): string {
        const stepAhead = Date.now() + MINUTE_STEP_MS;

        return new Date(
            Math.ceil(stepAhead / MINUTE_STEP_MS) * MINUTE_STEP_MS,
        ).toISOString();
    }

    /**
     * Seed the form from the viewer's current status each time the dialog opens,
     * so reopening after a cancel never shows a half-edited draft. An existing
     * expiry has no preset to map back to, so it opens on "Custom…" with the
     * controls pointed at it — kept exactly as stored, never nudged forward.
     */
    function reset(): void {
        minDate.value = today(effectiveZone.value);
        emoji.value = status.value?.emoji ?? null;
        text.value = status.value?.text ?? '';
        expiryKey.value = status.value?.expiresAt ? 'custom' : 'never';
        applyInstant(status.value?.expiresAt ?? defaultCustomInstant());
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

    // The expiry to send: the custom controls' instant while "Custom…" is
    // chosen, otherwise whatever the selected preset resolves to in the viewer's
    // zone.
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
    const previewStatus: ComputedRef<App.Data.UserStatusData | null> = computed(
        () =>
            effectiveEmoji.value
                ? { emoji: effectiveEmoji.value, text: null, expiresAt: null }
                : null,
    );

    function choosePreset(preset: StatusPreset): void {
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
                onError: () => toast.error(t('Could not save your status')),
                onFinish: () => (saving.value = false),
            },
        );
    }

    function clear(): void {
        saving.value = true;

        router.delete(destroyStatus().url, {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
            onError: () => toast.error(t('Could not clear your status')),
            onFinish: () => (saving.value = false),
        });
    }

    return {
        isEditing,
        presets,
        emoji,
        text,
        expiryKey,
        saving,
        dateValue,
        minDate,
        hour,
        minute,
        period,
        isExpiryInFuture,
        previewStatus,
        canSave,
        choosePreset,
        save,
        clear,
    };
}
