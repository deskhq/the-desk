import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';

export type UseDemoModeReturn = {
    /**
     * Whether this instance is the public single-shared-account demo. Destructive
     * owner-level controls read it to render themselves disabled; the server
     * enforces every block regardless (see PreventDestructiveDemoActions), so
     * this is UI affordance only.
     */
    demoMode: ComputedRef<boolean>;
    /**
     * ISO-8601 instant of the next hourly demo wipe, shared by the backend so the
     * banner countdown ticks against the real schedule. Null off the demo.
     */
    demoResetsAt: ComputedRef<string | null>;
};

export function useDemoMode(): UseDemoModeReturn {
    const page = usePage();

    return {
        demoMode: computed(() => page.props.demoMode === true),
        demoResetsAt: computed(() =>
            typeof page.props.demoResetsAt === 'string'
                ? page.props.demoResetsAt
                : null,
        ),
    };
}
