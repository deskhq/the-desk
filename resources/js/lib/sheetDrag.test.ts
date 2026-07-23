import { describe, expect, it } from 'vitest';

import { sheetOffset, shouldDismissSheet } from '@/lib/sheetDrag';

describe('sheetOffset', () => {
    it('follows a downward drag one-for-one', () => {
        expect(sheetOffset(120)).toBe(120);
    });

    it('stays put at the start of a drag', () => {
        expect(sheetOffset(0)).toBe(0);
    });

    it('resists an upward drag rather than lifting off its edge', () => {
        // The sheet is anchored to the bottom of the screen. Dragging up has
        // nowhere to go, so it gives a little and stops — pulling it into the
        // middle of the viewport would leave a gap under it.
        expect(sheetOffset(-100)).toBeGreaterThan(-100);
        expect(sheetOffset(-100)).toBeLessThan(0);
    });

    it('never lets an upward drag exceed the rubber band', () => {
        expect(sheetOffset(-10_000)).toBeGreaterThanOrEqual(-48);
    });
});

describe('shouldDismissSheet', () => {
    it('dismisses a drag past a third of the sheet', () => {
        expect(
            shouldDismissSheet({ offset: 200, height: 500, velocity: 0 }),
        ).toBe(true);
    });

    it('keeps a drag that stopped short of it', () => {
        expect(
            shouldDismissSheet({ offset: 80, height: 500, velocity: 0 }),
        ).toBe(false);
    });

    it('dismisses a short flick, because speed reads as intent', () => {
        // A quick flick downward is how a sheet is thrown away on a phone: the
        // finger barely travels, so distance alone would keep it open.
        expect(
            shouldDismissSheet({ offset: 60, height: 500, velocity: 1.2 }),
        ).toBe(true);
    });

    it('keeps a slow drag however fast the finger came back up', () => {
        // Velocity only counts downward: flicking back up is someone changing
        // their mind, not throwing the sheet away.
        expect(
            shouldDismissSheet({ offset: 20, height: 500, velocity: -3 }),
        ).toBe(false);
    });

    it('keeps an upward drag whatever the sheet measures', () => {
        expect(
            shouldDismissSheet({ offset: -40, height: 500, velocity: 0 }),
        ).toBe(false);
    });

    it('falls back to an absolute travel when the sheet has no measured height', () => {
        // A sheet mid-animation can measure zero; a fraction of zero would then
        // dismiss on the first pixel of a drag.
        expect(shouldDismissSheet({ offset: 4, height: 0, velocity: 0 })).toBe(
            false,
        );
        expect(shouldDismissSheet({ offset: 200, height: 0, velocity: 0 })).toBe(
            true,
        );
    });
});
