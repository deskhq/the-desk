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
     *
     * The swap is the only thing driving the formatters, so a failed write has
     * to put the previous style back — otherwise the whole interface keeps
     * rendering a clock the account never actually stored, until the next full
     * reload reseeds it from the shared prop.
     */
    function updateTimeFormat(next: TimeFormat): void {
        const previous = timeFormat.value;

        setTimeFormat(next);

        router.patch(
            update().url,
            { time_format: next },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => setTimeFormat(previous),
            },
        );
    }

    return { timeFormat, updateTimeFormat };
}
