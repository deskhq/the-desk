import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { setTimeFormat } from '@/lib/clock';
import { update } from '@/routes/time-format';
import type { TimeFormat } from '@/types';

/**
 * Read and mutate the current user's clock-style preference. The value is the
 * shared `auth.user.time_format` prop, so every consumer stays in sync.
 */
export function useTimeFormat() {
    const page = usePage();

    const timeFormat = computed<TimeFormat>(
        () => page.props.auth.user?.time_format ?? 'auto',
    );

    /**
     * Switch clock style: swap the active style first so every rendered time of
     * day re-renders immediately without a full reload, then persist the choice.
     * The shared prop refreshes from the redirect, so no optimistic state is
     * needed.
     */
    function updateTimeFormat(next: TimeFormat): void {
        setTimeFormat(next);

        router.patch(
            update().url,
            { time_format: next },
            { preserveScroll: true, preserveState: true },
        );
    }

    return { timeFormat, updateTimeFormat };
}
