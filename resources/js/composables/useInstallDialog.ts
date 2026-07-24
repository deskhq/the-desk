import { ref } from 'vue';

/**
 * Shared open-state for the install sheet. The sheet is mounted once in the main
 * layout, but it is opened from both install surfaces — the sidebar card and the
 * user menu's row — so the state lives here rather than being prop-drilled
 * through the dock.
 */
const isOpen = ref(false);

export function useInstallDialog() {
    return {
        isOpen,
        open: () => (isOpen.value = true),
    };
}
