import { ref } from 'vue';

/**
 * Shared open-state for the keyboard shortcuts help modal. The modal is mounted
 * once in the channels layout, but it can be opened from anywhere (the `?`
 * shortcut, the user menu) without prop-drilling through the component tree.
 */
const isOpen = ref(false);

export function useKeyboardShortcutsModal() {
    return {
        isOpen,
        open: () => (isOpen.value = true),
        toggle: () => (isOpen.value = !isOpen.value),
    };
}
