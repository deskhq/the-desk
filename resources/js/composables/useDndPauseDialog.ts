import { ref } from 'vue';

/**
 * Shared open-state for the custom "Pause notifications" dialog. The dialog is
 * mounted once in the main layout, but it is opened from the presence menu's
 * pause flyout — so the state lives here rather than being prop-drilled
 * through the sidebar, mirroring the status dialog.
 */
const isOpen = ref(false);

export function useDndPauseDialog() {
    return {
        isOpen,
        open: () => (isOpen.value = true),
    };
}
