import { describe, expect, it } from 'vitest';

import { swipeIntent } from '@/lib/edgeSwipe';

/** A left-edge start, as a phone's back-swipe zone would report it. */
const fromLeftEdge = {
    startX: 6,
    startY: 300,
    viewportWidth: 390,
    edge: 'left' as const,
};

describe('swipeIntent, for a dock on the left', () => {
    it('opens the dock on a rightward drag that began at the left edge', () => {
        expect(swipeIntent({ ...fromLeftEdge, endX: 90, endY: 306 })).toBe(
            'open',
        );
    });

    it('ignores a rightward drag that began away from the edge', () => {
        expect(
            swipeIntent({
                startX: 120,
                startY: 300,
                viewportWidth: 390,
                edge: 'left',
                endX: 220,
                endY: 306,
            }),
        ).toBe(null);
    });

    it('ignores a drag too short to be deliberate', () => {
        expect(swipeIntent({ ...fromLeftEdge, endX: 40, endY: 300 })).toBe(
            null,
        );
    });

    it('ignores a drag that is mostly vertical', () => {
        // Scrolling the timeline with a thumb near the edge must not open the
        // dock, however far the finger travels.
        expect(swipeIntent({ ...fromLeftEdge, endX: 90, endY: 500 })).toBe(
            null,
        );
    });

    it('closes the dock on a leftward drag, wherever it began', () => {
        expect(
            swipeIntent({
                startX: 250,
                startY: 300,
                viewportWidth: 390,
                edge: 'left',
                endX: 120,
                endY: 306,
            }),
        ).toBe('close');
    });

    it('ignores a leftward drag too short to be deliberate', () => {
        expect(
            swipeIntent({
                startX: 250,
                startY: 300,
                viewportWidth: 390,
                edge: 'left',
                endX: 220,
                endY: 300,
            }),
        ).toBe(null);
    });
});

/** The mirror image, for someone who has moved their dock to the right. */
const fromRightEdge = {
    startX: 384,
    startY: 300,
    viewportWidth: 390,
    edge: 'right' as const,
};

describe('swipeIntent, for a dock on the right', () => {
    it('opens the dock on a leftward drag that began at the right edge', () => {
        expect(swipeIntent({ ...fromRightEdge, endX: 300, endY: 306 })).toBe(
            'open',
        );
    });

    it('ignores a leftward drag that began away from the edge', () => {
        expect(
            swipeIntent({
                startX: 270,
                startY: 300,
                viewportWidth: 390,
                edge: 'right',
                endX: 170,
                endY: 306,
            }),
        ).toBe(null);
    });

    it('closes the dock on a rightward drag, wherever it began', () => {
        expect(
            swipeIntent({
                startX: 140,
                startY: 300,
                viewportWidth: 390,
                edge: 'right',
                endX: 280,
                endY: 306,
            }),
        ).toBe('close');
    });
});
