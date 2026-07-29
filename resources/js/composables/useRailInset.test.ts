// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import {
    RAIL_BOTTOM_INSET_PROPERTY,
    RAIL_INSET_PROPERTY,
    useRailBottomInset,
    useRailInset,
} from '@/composables/useRailInset';

/** Let the composable's settle loop run one more frame. */
function nextFrame(): Promise<void> {
    return new Promise((resolve) => requestAnimationFrame(() => resolve()));
}

/** Resolved `position` per stub panel, since jsdom resolves no Tailwind class. */
const positions = new WeakMap<Element, string>();

/**
 * jsdom lays nothing out, so the panel's geometry and its resolved position are
 * stubbed to whatever the case under test needs.
 */
function panelStub(left: number, position = 'relative'): HTMLElement {
    const element = document.createElement('aside');
    element.getBoundingClientRect = () => ({ left }) as DOMRect;
    positions.set(element, position);
    document.body.append(element);

    return element;
}

function published(): string {
    return document.documentElement.style.getPropertyValue(RAIL_INSET_PROPERTY);
}

/** Run the composable in its own scope so unmount can be driven by hand. */
function harness(element: HTMLElement | null): {
    panel: ReturnType<typeof ref<HTMLElement | null>>;
    stop: () => void;
} {
    const panel = ref<HTMLElement | null>(element);
    const scope = effectScope();

    scope.run(() => useRailInset(panel));

    return { panel, stop: () => scope.stop() };
}

describe('useRailInset', () => {
    beforeEach(() => {
        document.documentElement.style.removeProperty(RAIL_INSET_PROPERTY);
        document.body.replaceChildren();
        vi.stubGlobal('innerWidth', 1440);
        vi.stubGlobal('getComputedStyle', (element: Element) => ({
            position: positions.get(element) ?? 'static',
        }));
        vi.stubGlobal(
            'ResizeObserver',
            class {
                observe = vi.fn();
                disconnect = vi.fn();
            },
        );
    });

    it('publishes how much of the right edge the panel claims', async () => {
        const { stop } = harness(panelStub(1080));
        await nextTick();

        expect(published()).toBe('360px');

        stop();
    });

    it('publishes nothing when no panel is open, so the rail falls back to the viewport edge', async () => {
        harness(null);
        await nextTick();

        expect(published()).toBe('');
    });

    it('publishes nothing while the panel overlays the pane instead of sitting beside it', async () => {
        harness(panelStub(0, 'absolute'));
        await nextTick();

        expect(published()).toBe('');
    });

    it('stops claiming the edge once the panel closes', async () => {
        const { panel } = harness(panelStub(1080));
        await nextTick();

        panel.value = null;
        await nextTick();

        expect(published()).toBe('');
    });

    it('keeps looking while the panel arrives, since neither its reflow nor its slide is observable', async () => {
        // Measured off the right edge on mount, the way a panel that is still
        // translated (or beside a pane that has not reflowed yet) reads.
        const panel = panelStub(1430);
        harness(panel);
        await nextTick();

        expect(published()).toBe('10px');

        panel.getBoundingClientRect = () => ({ left: 1080 }) as DOMRect;
        await nextFrame();

        expect(published()).toBe('360px');
    });

    it('never claims a negative inset', async () => {
        harness(panelStub(2000));
        await nextTick();

        expect(published()).toBe('0px');
    });

    it('releases the edge when the pane that published it goes away', async () => {
        const { stop } = harness(panelStub(1080));
        await nextTick();

        stop();

        expect(published()).toBe('');
    });
});

/** A composer occupying the given height at the foot of an 900px viewport. */
function composerStub(height: number): HTMLElement {
    const element = document.createElement('div');
    element.getBoundingClientRect = () => ({ top: 900 - height }) as DOMRect;
    document.body.append(element);

    return element;
}

function publishedBottom(): string {
    return document.documentElement.style.getPropertyValue(
        RAIL_BOTTOM_INSET_PROPERTY,
    );
}

describe('useRailBottomInset', () => {
    let observed: unknown[];

    beforeEach(() => {
        document.documentElement.style.removeProperty(
            RAIL_BOTTOM_INSET_PROPERTY,
        );
        document.body.replaceChildren();
        vi.stubGlobal('innerHeight', 900);
        observed = [];
        vi.stubGlobal(
            'ResizeObserver',
            class {
                observe = (target: unknown): void => {
                    observed.push(target);
                };
                disconnect = vi.fn();
            },
        );
    });

    it('publishes how much of the bottom edge the composer claims', async () => {
        const scope = effectScope();
        scope.run(() =>
            useRailBottomInset(ref<HTMLElement | null>(composerStub(96))),
        );
        await nextTick();

        expect(publishedBottom()).toBe('96px');

        scope.stop();
    });

    it('publishes nothing on a page with no composer, so the rail falls back to the viewport floor', async () => {
        const scope = effectScope();
        scope.run(() => useRailBottomInset(ref<HTMLElement | null>(null)));
        await nextTick();

        expect(publishedBottom()).toBe('');

        scope.stop();
    });

    it('publishes nothing, rather than throwing, when the composer resolves to a fragment anchor', async () => {
        // A component whose template opens with a comment is a fragment in a
        // dev build, and its `$el` is then the anchor comment (#1051).
        const anchor = document.createComment('') as unknown as HTMLElement;
        const scope = effectScope();
        scope.run(() => useRailBottomInset(ref<HTMLElement | null>(anchor)));
        await nextTick();

        expect(publishedBottom()).toBe('');
        expect(observed).toEqual([]);

        scope.stop();
    });

    it('claims the floor again once the anchor is replaced by the composer itself', async () => {
        const composer = ref<HTMLElement | null>(
            document.createComment('') as unknown as HTMLElement,
        );
        const scope = effectScope();
        scope.run(() => useRailBottomInset(composer));
        await nextTick();

        composer.value = composerStub(96);
        await nextTick();

        expect(publishedBottom()).toBe('96px');

        scope.stop();
    });

    it('releases the floor when the conversation pane goes away', async () => {
        const scope = effectScope();
        scope.run(() =>
            useRailBottomInset(ref<HTMLElement | null>(composerStub(96))),
        );
        await nextTick();

        scope.stop();

        expect(publishedBottom()).toBe('');
    });
});
