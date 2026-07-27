// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { computed, effectScope, nextTick } from 'vue';

// `ref` is not in scope where `vi.hoisted` runs — it lands above the imports —
// so the stand-in store has to reach for Vue itself.
const { activeToasts } = await vi.hoisted(async () => {
    const { ref: hoistedRef } = await import('vue');

    return { activeToasts: hoistedRef<unknown[]>([]) };
});

vi.mock('@/composables/useToast', () => ({
    useToastCount: () => computed(() => activeToasts.value.length),
}));

import { useToastZoneHeight } from '@/composables/useToastZoneHeight';

/** Put a front toast of the given height into the DOM, the way sonner does. */
function renderFrontToast(offsetHeight: number): void {
    const toast = document.createElement('li');
    toast.setAttribute('data-sonner-toast', '');
    toast.setAttribute('data-front', 'true');
    Object.defineProperty(toast, 'offsetHeight', { value: offsetHeight });
    document.body.append(toast);
}

function harness(): { height: { value: number }; stop: () => void } {
    const scope = effectScope();

    let height!: { value: number };

    scope.run(() => {
        height = useToastZoneHeight();
    });

    return { height, stop: () => scope.stop() };
}

describe('useToastZoneHeight', () => {
    beforeEach(() => {
        document.body.replaceChildren();
        activeToasts.value = [];
        vi.stubGlobal(
            'ResizeObserver',
            class {
                observe = vi.fn();
                disconnect = vi.fn();
            },
        );
    });

    it('claims no room while no toast is up, leaving the nudges where they were', async () => {
        const { height } = harness();
        await nextTick();

        expect(height.value).toBe(0);
    });

    it('claims the front toast’s height plus the gap between the two zones', async () => {
        const { height } = harness();

        renderFrontToast(56);
        activeToasts.value = [{}];
        await nextTick();
        await nextTick();

        expect(height.value).toBe(66);
    });

    it('gives the room back once the last toast goes', async () => {
        const { height } = harness();

        renderFrontToast(56);
        activeToasts.value = [{}];
        await nextTick();
        await nextTick();

        document.body.replaceChildren();
        activeToasts.value = [];
        await nextTick();
        await nextTick();

        expect(height.value).toBe(0);
    });
});
