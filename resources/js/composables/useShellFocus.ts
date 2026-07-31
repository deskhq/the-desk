import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { useToastCount } from '@/composables/useToast';

/**
 * sonner's stock hotkey, which it keeps whenever the rail has no toasts on it.
 * Restoring it there rather than leaving F6 bound is the point: sonner's handler
 * focuses its list unconditionally, and an F6 that pulled the caret out of the
 * composer into an empty corner would be worse than no shortcut at all.
 */
const SONNER_DEFAULT_HOTKEY = ['altKey', 'KeyT'];

/**
 * 16px in from the pane's right edge and 16px above its composer, per the
 * placement in #978. Both insets are published by whatever the pane has at those
 * edges and default to zero, so on a page with neither — settings, auth — the
 * rail falls back to the viewport corner.
 */
export const TOAST_OFFSET = {
    right: 'calc(1rem + var(--rail-right-inset, 0px))',
    bottom: 'calc(1rem + var(--rail-bottom-inset, 0px))',
};

/**
 * Below `md` the toast goes full width minus a 10px gutter and sits 12px above
 * the composer. The composer pads itself by the software keyboard's inset and
 * publishes the result, so the rail lifts with the keyboard rather than hiding
 * behind it.
 */
export const TOAST_MOBILE_OFFSET = {
    left: '10px',
    right: '10px',
    bottom: 'calc(12px + var(--rail-bottom-inset, 0px))',
};

export interface ShellFocus {
    /** What F6 is bound to right now — sonner's list, or its own default. */
    toasterHotkey: ComputedRef<string[]>;
    /** Move focus to the nudge zone, the rail's upper half. */
    focusNotificationRail: () => void;
}

/**
 * Who owns the caret when the viewer reaches for the bottom-right rail.
 *
 * The rail is one surface with two zones — transient toasts at the foot,
 * persistent reminder nudges above — and F6 goes to whichever cards are on it.
 * Only the upper zone is ours.
 *
 * The toast zone belongs to sonner, and {@link ShellFocus.toasterHotkey} hands
 * F6 to it while it has something to show: sonner's own handler expands the
 * stack, which is what *pauses* the dismiss timers, and none of that is
 * reachable from out here (focusing the list is not enough — sonner counts only
 * a pointer press as interaction). So the handler below owns the zone sonner
 * knows nothing about, and takes precedence when both are occupied: the nudges
 * are the upper zone and the persistent cards, and sonner's handler has already
 * run by the time this one does.
 *
 * The shortcut registry entry remains the single source of truth for what F6
 * does and how it is documented; this is only the handover.
 */
export function useShellFocus(): ShellFocus {
    /** How many toasts are up, so F6 only claims focus for a rail that has cards. */
    const toastCount = useToastCount();

    const toasterHotkey = computed<string[]>(() =>
        toastCount.value > 0 ? ['F6'] : SONNER_DEFAULT_HOTKEY,
    );

    function focusNotificationRail(): void {
        document
            .querySelector<HTMLElement>('[data-test="reminder-nudges"]')
            ?.focus();
    }

    return { toasterHotkey, focusNotificationRail };
}
