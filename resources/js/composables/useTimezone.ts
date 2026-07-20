import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { backgroundVisit } from '@/lib/backgroundVisit';
import { update } from '@/routes/timezone';

/**
 * Fires at most once per app lifetime so a stale in-flight detection doesn't
 * repeat across persistent-layout re-renders.
 */
let autoDetected = false;

/**
 * Read and mutate the current user's timezone. The value is the shared
 * `auth.user.timezone` prop, so every consumer stays in sync and the shared prop
 * refreshes from the redirect — no optimistic state needed.
 */
export function useTimezone() {
    const page = usePage();

    const timezone = computed<string | null>(
        () => page.props.auth.user.timezone ?? null,
    );

    function persist(value: string, options: Record<string, unknown>): void {
        router.patch(
            update().url,
            { timezone: value },
            { preserveScroll: true, preserveState: true, ...options },
        );
    }

    /**
     * Persist a manual timezone choice. The user picked it and is watching the
     * settings form, so this stays a foreground visit.
     */
    function setTimezone(value: string): void {
        persist(value, {});
    }

    /**
     * On first authenticated load, persist the browser's detected zone when the
     * user has none stored yet. A no-op once a zone is known.
     */
    function syncDetectedTimezone(): void {
        if (autoDetected || timezone.value) {
            return;
        }

        autoDetected = true;

        const detected = Intl.DateTimeFormat().resolvedOptions().timeZone;

        if (detected) {
            // Nobody asked for this write and it lands moments after the first
            // authenticated render, when the user is most likely mid-click; see
            // {@see backgroundVisit}.
            persist(detected, backgroundVisit);
        }
    }

    return { timezone, setTimezone, syncDetectedTimezone };
}
