import { ref } from 'vue';
import type { Ref } from 'vue';

// Appended to alternate announcements. A non-breaking space is unspoken by
// screen readers yet survives HTML whitespace collapsing, so toggling it is a
// real text mutation the live region detects — see {@see useSendFailureAnnouncer}.
const NBSP = ' ';

/**
 * Drives a visually-hidden `aria-live="polite"` region that announces message
 * send/rollback failures to screen readers.
 *
 * A failed optimistic send rolls its row back silently, so a sighted user sees
 * the row vanish and a toast, but a screen-reader user gets nothing. This
 * exposes an `announcement` string a live region renders, and an `announce`
 * call the send path fires on failure.
 *
 * Two identical failures in a row (e.g. retrying the same message) would leave
 * the live region's text unchanged, and a region only re-announces when its
 * content mutates. So each call toggles a trailing {@see NBSP} — enough of a
 * DOM change to re-fire the announcement without altering the spoken text.
 */
export function useSendFailureAnnouncer(): {
    announcement: Ref<string>;
    announce: (message: string) => void;
} {
    const announcement = ref('');
    let parity = 0;

    function announce(message: string): void {
        parity += 1;
        announcement.value = parity % 2 === 0 ? `${message}${NBSP}` : message;
    }

    return { announcement, announce };
}
