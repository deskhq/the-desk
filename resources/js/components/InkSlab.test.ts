// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { createApp, h } from 'vue';
import InkSlab from './InkSlab.vue';

/** Mount the slab and hand back its root element. */
function mount(
    props: Record<string, unknown> = {},
    slot = 'body',
): HTMLElement {
    const host = document.createElement('div');
    document.body.append(host);

    createApp({
        render: () => h(InkSlab, props, { default: () => slot }),
    }).mount(host);

    return host.firstElementChild as HTMLElement;
}

describe('InkSlab', () => {
    it('is the inverse of the surface it interrupts, from the flipping token pair', () => {
        const slab = mount();

        expect(slab.className).toContain('bg-primary');
        expect(slab.className).toContain('text-primary-foreground');
    });

    it('carries no border — the elevation is the shadow', () => {
        const slab = mount();

        expect(slab.className).not.toMatch(/(^|\s)border($|\s|-)/);
        expect(slab.className).toContain('shadow-');
    });

    it('never inherits the card or popover surface', () => {
        const slab = mount();

        expect(slab.className).not.toContain('bg-card');
        expect(slab.className).not.toContain('bg-popover');
    });

    it('warms the surface a step for an error rather than shouting in red', () => {
        const slab = mount({ tone: 'error' });

        expect(slab.className).toContain('bg-slab-error');
        expect(slab.className).not.toContain('bg-destructive');
    });

    it('re-enables pointer events the rail turns off for its gaps', () => {
        expect(mount().className).toContain('pointer-events-auto');
    });

    it('renders what it is given', () => {
        expect(mount({}, 'Reminder set').textContent).toContain('Reminder set');
    });

    it('lets the caller keep its own radius and padding', () => {
        const slab = mount({ class: 'rounded-2xl p-4' });

        expect(slab.className).toContain('rounded-2xl');
        expect(slab.className).toContain('p-4');
    });
});
