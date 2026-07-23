import { ref } from 'vue';

/**
 * Shared open-state for the quick switcher. The palette is mounted once in the
 * main layout, but below the breakpoint it is entered from the channel
 * masthead's search icon — a different subtree — so the state lives here
 * rather than being prop-drilled (the keyboard-shortcuts modal's pattern).
 */
const isOpen = ref(false);

export function useQuickSwitcher() {
    return {
        isOpen,
        open: (): void => {
            isOpen.value = true;
        },
        toggle: (): void => {
            isOpen.value = !isOpen.value;
        },
    };
}
