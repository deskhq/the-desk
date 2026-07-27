import { nextTick, onScopeDispose, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { useToastCount } from '@/composables/useToast';

/** The gap the rail keeps between its two zones. */
const ZONE_GAP_PX = 10;

/** The front toast — the only one of a collapsed stack with its own height. */
const FRONT_TOAST = '[data-sonner-toast][data-front="true"]';

/**
 * How much room the toast zone is taking at the foot of the bottom-right rail,
 * so the reminder-nudge zone above it can sit clear of it.
 *
 * sonner positions its toasts `fixed` and stacks them absolutely, which leaves
 * its own region with no height to flow around — so the zone above has to be
 * told. Zero whenever no toast is up, which is the overwhelmingly common case
 * and puts the nudges exactly where they have always been (#978).
 */
export function useToastZoneHeight(): Ref<number> {
    const height = ref(0);

    if (typeof window === 'undefined') {
        return height;
    }

    const toastCount = useToastCount();
    let observer: ResizeObserver | null = null;

    function measure(): void {
        const front = document.querySelector<HTMLElement>(FRONT_TOAST);

        height.value = front ? front.offsetHeight + ZONE_GAP_PX : 0;
    }

    watch(
        toastCount,
        async () => {
            await nextTick();

            observer?.disconnect();

            const front = document.querySelector<HTMLElement>(FRONT_TOAST);

            if (front) {
                // A toast that gains a detail line under the same merge key
                // grows without the stack's length ever changing.
                observer = new ResizeObserver(measure);
                observer.observe(front);
            }

            measure();
        },
        { immediate: true },
    );

    onScopeDispose(() => observer?.disconnect());

    return height;
}
