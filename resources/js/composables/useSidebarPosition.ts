import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SidebarPosition } from '@/types';
import { update } from '@/routes/sidebar-position';

/**
 * Read and mutate the current user's sidebar-position preference. The value is
 * the shared `auth.user.sidebar_position` prop, so every consumer (the settings
 * picker and the layout) stays in sync; the shared prop refreshes from the
 * redirect, so the sidebar re-binds its side live with no optimistic state.
 */
export function useSidebarPosition() {
    const page = usePage();

    const sidebarPosition = computed<SidebarPosition>(
        () => page.props.auth.user.sidebar_position ?? 'left',
    );

    /**
     * Persist a new sidebar position.
     */
    function updateSidebarPosition(position: SidebarPosition): void {
        router.patch(
            update().url,
            { sidebar_position: position },
            { preserveScroll: true, preserveState: true },
        );
    }

    return { sidebarPosition, updateSidebarPosition };
}
