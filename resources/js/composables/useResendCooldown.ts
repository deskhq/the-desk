import { computed, onScopeDispose, readonly, ref } from 'vue';
import type { ComputedRef, DeepReadonly, Ref } from 'vue';

export type UseResendCooldownReturn = {
    /** Seconds still to wait; 0 when the button is free. */
    remaining: DeepReadonly<Ref<number>>;
    /** Whether the button should currently refuse a press. */
    isCooling: ComputedRef<boolean>;
    /** The countdown as `m:ss`, for the button's label. */
    formatted: ComputedRef<string>;
    /** Begin (or restart) the window, after a send has succeeded. */
    start: () => void;
};

/**
 * Render a number of seconds as `m:ss`.
 */
export function formatCountdown(seconds: number): string {
    const safe = Math.max(0, Math.floor(seconds));

    return `${Math.floor(safe / 60)}:${String(safe % 60).padStart(2, '0')}`;
}

/**
 * Hold a resend button shut for a moment after a successful send.
 *
 * This is an anti-double-click affordance and nothing more: the server's
 * `password-reset` rate limiter is what actually stops abuse, and it keeps
 * working whether or not this countdown is honoured. So it always releases —
 * a stuck timer must never be able to lock someone out of asking again.
 */
export function useResendCooldown(seconds: number): UseResendCooldownReturn {
    const remaining = ref(0);
    let timer: ReturnType<typeof setInterval> | undefined;

    function stop(): void {
        if (timer !== undefined) {
            clearInterval(timer);
            timer = undefined;
        }
    }

    function start(): void {
        stop();
        remaining.value = seconds;

        timer = setInterval(() => {
            remaining.value = Math.max(0, remaining.value - 1);

            if (remaining.value === 0) {
                stop();
            }
        }, 1000);
    }

    onScopeDispose(stop);

    return {
        remaining: readonly(remaining),
        isCooling: computed(() => remaining.value > 0),
        formatted: computed(() => formatCountdown(remaining.value)),
        start,
    };
}
