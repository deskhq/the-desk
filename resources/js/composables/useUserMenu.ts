import { router, usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import {
    destroy as destroyDndPause,
    update as updateDndPause,
} from '@/actions/App/Http/Controllers/Settings/DndController';
import { update as snoozeDndSchedule } from '@/actions/App/Http/Controllers/Settings/DndScheduleSnoozeController';
import { update as updatePresence } from '@/actions/App/Http/Controllers/Settings/PresenceController';
import { destroy as destroyStatus } from '@/actions/App/Http/Controllers/Settings/StatusController';
import { useTranslations } from '@/composables/useTranslations';
import { formatTimeOfDay } from '@/lib/datetime';
import { isDndActiveNow, quietHoursEndsAt } from '@/lib/dnd';
import { DND_PAUSE_KEYS, resolveDndPause } from '@/lib/dndPause';
import type { DndPauseKey } from '@/lib/dndPause';
import type { RenderedPresence } from '@/lib/presence';
import type { Team } from '@/types';

export type UseUserMenuReturn = {
    currentTeam: ComputedRef<Team | null>;
    ownStatus: ComputedRef<App.Data.UserStatusData | null>;
    ownPresence: ComputedRef<RenderedPresence>;
    togglesTo: ComputedRef<RenderedPresence>;
    isDnd: ComputedRef<boolean>;
    pausedUntil: ComputedRef<string | null>;
    quietHoursUntil: ComputedRef<string | null>;
    clearsAt: ComputedRef<string | null>;
    pausePresets: DndPauseKey[];
    clearStatus: (event?: Event) => void;
    togglePresence: (event?: Event) => void;
    choosePause: (key: DndPauseKey, event?: Event) => void;
    resumeNotifications: (event?: Event) => void;
    snoozeSchedule: (event?: Event) => void;
    handleLogout: () => void;
};

/**
 * The user menu's shared state and actions — one source for the desktop
 * dropdown (`UserMenuContent`) and the mobile bottom sheet (`UserMenuSheet`),
 * so the two presentations of the same menu can never drift apart.
 *
 * Everything reads from the shared `auth.user` prop rather than a `user` prop
 * so a set/clear lands in the open menu without it remounting. The mutation
 * handlers take an optional event: the dropdown passes its `@select` event and
 * prevents the default so the row applies in place without dismissing the
 * menu; the sheet's plain buttons have no default to prevent.
 */
export function useUserMenu(): UseUserMenuReturn {
    const page = usePage();
    const { t } = useTranslations();

    const currentTeam = computed(() => page.props.currentTeam as Team | null);

    const ownStatus = computed(() => page.props.auth.user.status ?? null);

    /**
     * The viewer's own effective presence. Never "offline" — the menu is open,
     * so they are plainly here.
     */
    const ownPresence = computed<RenderedPresence>(
        () => page.props.auth.user.presence ?? 'active',
    );

    /** The state the toggle would switch to, which is also the glyph it previews. */
    const togglesTo = computed<RenderedPresence>(() =>
        ownPresence.value === 'away' ? 'active' : 'away',
    );

    /** The viewer's own full DND configuration, from the shared `auth.user` prop. */
    const ownDnd = computed(() => page.props.auth.user.dnd ?? null);

    const ownTimezone = computed(() => page.props.auth.user.timezone ?? null);

    /** Whether the viewer is in DND right now — a running pause or quiet hours. */
    const isDnd = computed(() =>
        isDndActiveNow(ownDnd.value, ownTimezone.value),
    );

    /** The running manual pause's lapse, formatted, or null when none runs. */
    const pausedUntil = computed(() =>
        ownDnd.value?.until
            ? formatTimeOfDay(
                  ownDnd.value.until,
                  ownTimezone.value ?? undefined,
              )
            : null,
    );

    /**
     * When the covering quiet-hours window closes, formatted. Only read when no
     * manual pause runs — a pause is the more specific claim, so its lapse wins
     * the card's subtitle.
     */
    const quietHoursUntil = computed(() => {
        const closes = quietHoursEndsAt(ownDnd.value, ownTimezone.value);

        return closes
            ? formatTimeOfDay(
                  closes.toISOString(),
                  ownTimezone.value ?? undefined,
              )
            : null;
    });

    /**
     * When the status clears, as a time of day in the viewer's own zone. Null
     * for a status that never clears, which then shows no second line at all.
     */
    const clearsAt = computed(() =>
        ownStatus.value?.expiresAt
            ? formatTimeOfDay(
                  ownStatus.value.expiresAt,
                  ownTimezone.value ?? undefined,
              )
            : null,
    );

    /**
     * Clear the status outright from the row's ✕, with no trip through the
     * dialog — the one-tap undo for "that meeting ended early".
     */
    function clearStatus(event?: Event): void {
        event?.preventDefault();

        router.delete(destroyStatus().url, {
            preserveScroll: true,
            onError: () => toast.error(t('Could not clear your status.')),
        });
    }

    /** Flip the manual away override, in place. */
    function togglePresence(event?: Event): void {
        event?.preventDefault();

        router.put(
            updatePresence().url,
            { state: togglesTo.value },
            {
                preserveScroll: true,
                onError: () =>
                    toast.error(t('Could not change your presence.')),
            },
        );
    }

    /** The preset rows: everything but Custom…, which opens the dialog. */
    const pausePresets = DND_PAUSE_KEYS.filter((key) => key !== 'custom');

    /** Start a pause from a preset, so the STATUS section grows the paused card in place. */
    function choosePause(key: DndPauseKey, event?: Event): void {
        event?.preventDefault();

        const until = resolveDndPause(
            key,
            ownTimezone.value ??
                new Intl.DateTimeFormat().resolvedOptions().timeZone,
        );

        if (until === null) {
            return;
        }

        router.put(
            updateDndPause().url,
            { until },
            {
                preserveScroll: true,
                onError: () =>
                    toast.error(t('Could not pause your notifications.')),
            },
        );
    }

    /** End the manual pause early, so the card collapses back to the plain rows. */
    function resumeNotifications(event?: Event): void {
        event?.preventDefault();

        router.delete(destroyDndPause().url, {
            preserveScroll: true,
            onError: () =>
                toast.error(t('Could not resume your notifications.')),
        });
    }

    /**
     * Lift tonight's quiet-hours window without disabling the standing
     * schedule — the server suppresses the window until it next closes, then
     * the schedule resumes on its own.
     */
    function snoozeSchedule(event?: Event): void {
        event?.preventDefault();

        router.put(
            snoozeDndSchedule().url,
            {},
            {
                preserveScroll: true,
                onError: () =>
                    toast.error(t('Could not snooze your quiet hours.')),
            },
        );
    }

    function handleLogout(): void {
        router.flushAll();
    }

    return {
        currentTeam,
        ownStatus,
        ownPresence,
        togglesTo,
        isDnd,
        pausedUntil,
        quietHoursUntil,
        clearsAt,
        pausePresets,
        clearStatus,
        togglePresence,
        choosePause,
        resumeNotifications,
        snoozeSchedule,
        handleLogout,
    };
}
