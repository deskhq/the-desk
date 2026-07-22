import { ref } from 'vue';

/**
 * Shared open-state for the "Set a status" dialog. The dialog is mounted once in
 * the main layout, but it is opened from the user menu's presence section — so
 * the state lives here rather than being prop-drilled through the sidebar.
 */
const isOpen = ref(false);

export function useUserStatusDialog() {
    return {
        isOpen,
        open: () => (isOpen.value = true),
    };
}
