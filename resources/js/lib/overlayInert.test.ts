// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { initializeOverlayInert } from '@/lib/overlayInert';

/**
 * Wait for the `MutationObserver` callback, which the platform delivers as a
 * microtask once the current task settles.
 */
const flush = (): Promise<void> =>
    new Promise((resolve) => setTimeout(resolve));

let dispose: (() => void) | null = null;

function start(): void {
    dispose = initializeOverlayInert();
}

/** Stand in for what the `aria-hidden` package does when an overlay opens. */
function hide(element: HTMLElement): void {
    element.setAttribute('data-aria-hidden', 'true');
    element.setAttribute('aria-hidden', 'true');
}

/** …and what it does when that overlay closes again. */
function reveal(element: HTMLElement): void {
    element.removeAttribute('data-aria-hidden');
    element.removeAttribute('aria-hidden');
}

function shell(): HTMLElement {
    const element = document.createElement('div');
    element.innerHTML = '<a href="#main">Skip to content</a>';
    document.body.append(element);

    return element;
}

afterEach(() => {
    dispose?.();
    dispose = null;
    document.body.innerHTML = '';
});

describe('initializeOverlayInert', () => {
    it('makes the region an overlay hides inert', async () => {
        const region = shell();
        start();

        hide(region);
        await flush();

        expect(region.hasAttribute('inert')).toBe(true);
    });

    it('hands the region back when the overlay closes', async () => {
        const region = shell();
        start();

        hide(region);
        await flush();
        reveal(region);
        await flush();

        expect(region.hasAttribute('inert')).toBe(false);
        expect(region.hasAttribute('data-overlay-inert')).toBe(false);
    });

    it('covers a region already hidden before it starts', async () => {
        const region = shell();
        hide(region);

        start();

        expect(region.hasAttribute('inert')).toBe(true);
    });

    it('covers every region an overlay hides, not just the first', async () => {
        const first = shell();
        const second = shell();
        start();

        hide(first);
        hide(second);
        await flush();

        expect(first.hasAttribute('inert')).toBe(true);
        expect(second.hasAttribute('inert')).toBe(true);
    });

    it('leaves an inert the app owns alone', async () => {
        const region = shell();
        region.setAttribute('inert', '');
        start();

        hide(region);
        await flush();
        reveal(region);
        await flush();

        // Never marked as ours, so the app's own inert survives the round trip.
        expect(region.hasAttribute('data-overlay-inert')).toBe(false);
        expect(region.hasAttribute('inert')).toBe(true);
    });

    it('stops mirroring once disposed', async () => {
        const region = shell();
        start();

        dispose?.();
        dispose = null;

        hide(region);
        await flush();

        expect(region.hasAttribute('inert')).toBe(false);
    });
});
