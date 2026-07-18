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
};

export function useDemoMode(): UseDemoModeReturn {
    const page = usePage();

    return {
        demoMode: computed(() => page.props.demoMode === true),
    };
}
