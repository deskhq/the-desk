// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest';
import { createApp, h, ref } from 'vue';

/**
 * Covers the two slots a progress card needs that no other tone does: the muted
 * continuation of the title, and the figure hard right. The rest of the card —
 * surface, tones, drain — is the slab's and `useToast`'s.
 */
vi.mock('vue-sonner', () => ({
    toast: { custom: vi.fn(), dismiss: vi.fn() },
    useVueSonner: () => ({ activeToasts: ref([{ id: 1 }, { id: 2 }]) }),
}));

import ToastCard from './ToastCard.vue';

/** Mount the card and hand back its root element. */
function mount(props: Record<string, unknown> = {}): HTMLElement {
    const host = document.createElement('div');
    document.body.append(host);

    const app = createApp({
        render: () =>
            h(ToastCard, {
                tone: 'progress',
                title: 'Uploading 3 files',
                duration: Infinity,
                ...props,
            }),
    });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    return host.firstElementChild as HTMLElement;
}

/** The element a `data-test` hook names, or null. */
function slot(card: HTMLElement, name: string): HTMLElement | null {
    return card.querySelector<HTMLElement>(`[data-test="${name}"]`);
}

describe('ToastCard', () => {
    it('continues the title with its meta rather than starting a second line', () => {
        const card = mount({ meta: '12.4 MB' });

        expect(slot(card, 'toast-title')?.textContent).toBe(
            'Uploading 3 files · 12.4 MB',
        );
        expect(slot(card, 'toast-detail')).toBeNull();
    });

    it('leaves the title alone when there is no meta', () => {
        expect(slot(mount(), 'toast-title')?.textContent).toBe(
            'Uploading 3 files',
        );
    });

    it('sets a value hard right in monospace', () => {
        const value = slot(mount({ value: '64%' }), 'toast-value');

        expect(value?.textContent).toBe('64%');
        expect(value?.className).toContain('font-mono');
    });

    it('hides the value from the announcement, which repeats every tick', () => {
        // vue-sonner announces changed text inside a live toast, so a percent
        // re-firing once a second would be read aloud about sixty times a
        // minute. The title and meta carry what has to be heard.
        expect(
            slot(mount({ value: '64%' }), 'toast-value')?.getAttribute(
                'aria-hidden',
            ),
        ).toBe('true');
    });

    it('gives the value the slot the stack’s overflow count would take', () => {
        // Two toasts are active, so the front card would otherwise show "+1".
        const card = mount({ value: '64%' });

        expect(slot(card, 'toast-overflow')).toBeNull();
        expect(slot(card, 'toast-value')).not.toBeNull();
    });

    it('still counts the stack when the card carries no value', () => {
        expect(slot(mount(), 'toast-overflow')?.textContent).toBe('+1');
    });
});
