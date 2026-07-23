import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';
import { keyboardInset } from '@/lib/keyboardInset';

/**
 * How many pixels the on-screen keyboard currently covers, kept live off the
 * visualViewport API.
 *
 * Zero on every device without a software keyboard, and during SSR — so a
 * surface can pad by it unconditionally.
 */
export function useKeyboardInset(): Ref<number> {
    const inset = ref(0);

    function measure(): void {
        const viewport = window.visualViewport;

        inset.value = viewport
            ? keyboardInset({
                  innerHeight: window.innerHeight,
                  height: viewport.height,
                  offsetTop: viewport.offsetTop,
              })
            : 0;
    }

    onMounted(() => {
        measure();
        window.visualViewport?.addEventListener('resize', measure);
        window.visualViewport?.addEventListener('scroll', measure);
    });

    onBeforeUnmount(() => {
        window.visualViewport?.removeEventListener('resize', measure);
        window.visualViewport?.removeEventListener('scroll', measure);
    });

    return inset;
}
