import type { Ref } from 'vue';
import { useSidebar } from '@/components/ui/sidebar';
import { useIsMobile } from '@/composables/useIsMobile';
import { useNavPanel } from '@/composables/useNavPanel';
import { useQuickSwitcher } from '@/composables/useQuickSwitcher';

export interface MastheadSearch {
    /**
     * Whether the viewport is below the breakpoint. It decides where the glyph
     * sends the viewer, and it is the same answer the masthead's controls fold
     * on, so they read it from here rather than asking a second time.
     */
    isMobile: Ref<boolean>;
    openSearch: () => void;
}

/**
 * Where the masthead's search glyph sends the viewer.
 *
 * Below the breakpoint it is the jump-to overlay's entry point (m5): a phone
 * has no ⌘K, and the overlay leads on to the Search panel via its message
 * results. From `md` up, search is a dock destination, so the glyph swaps the
 * panel rather than navigating anywhere — the channel it was pressed from stays
 * open behind it. A collapsed dock is expanded first, or the click would pin
 * `?nav=search` on a panel nobody can see.
 *
 * The two behaviours share one control rather than a `v-if` pair: one button,
 * one selector, and no chance of the pair disagreeing about which is rendered.
 */
export function useMastheadSearch(): MastheadSearch {
    const isMobile = useIsMobile();
    const { open: openQuickSwitcher } = useQuickSwitcher();
    const { openDestination } = useNavPanel();
    const { open: dockOpen, setOpen: setDockOpen } = useSidebar();

    function openSearch(): void {
        if (isMobile.value) {
            openQuickSwitcher();

            return;
        }

        if (!dockOpen.value) {
            setDockOpen(true);
        }

        openDestination('search');
    }

    return { isMobile, openSearch };
}
