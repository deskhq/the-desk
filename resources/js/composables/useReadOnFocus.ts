import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Advance the given read pointers whenever the tab regains focus.
 *
 * A conversation is only "read" while the viewer is actually looking at it, so
 * the pointers ride the window's focus event for as long as the page is
 * mounted. The posts cancel themselves on teardown, so leaving the workspace
 * never fires a stale mark-read.
 */
export function useReadOnFocus(...markRead: Array<() => void>): void {
    onMounted(() => {
        for (const mark of markRead) {
            window.addEventListener('focus', mark);
        }
    });

    onBeforeUnmount(() => {
        for (const mark of markRead) {
            window.removeEventListener('focus', mark);
        }
    });
}
