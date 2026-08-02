import { describe, expect, it } from 'vitest';
import { storageReadout } from '@/lib/storageUsage';
import type { TeamStorage } from '@/types';

/**
 * The workspace storage read-out, away from the markup that renders it: the
 * copy each usage level produces, the tone that pairs with it, and the clamping
 * the bar needs once the quota is overshot.
 */
const MEGABYTE = 1024 * 1024;

function storage(overrides: Partial<TeamStorage> = {}): TeamStorage {
    return {
        usedBytes: MEGABYTE,
        quotaBytes: 4 * MEGABYTE,
        percent: 25,
        ...overrides,
    };
}

describe('the read-out copy', () => {
    it('states the percent, the raw sizes and the space left', () => {
        const readout = storageReadout(storage());

        expect(readout.percentText).toBe('25% used');
        expect(readout.sizeText).toBe('1 MB of 4 MB');
        expect(readout.remainingText).toBe('3 MB free');
    });

    it('reports the overshoot once the quota is already spent', () => {
        const readout = storageReadout(
            storage({ usedBytes: 6 * MEGABYTE, percent: 150 }),
        );

        expect(readout.percentText).toBe('150% used');
        expect(readout.remainingText).toBe('2 MB over the limit');
    });

    it('reads a full-to-the-byte workspace as having no space left', () => {
        const readout = storageReadout(
            storage({ usedBytes: 4 * MEGABYTE, percent: 100 }),
        );

        expect(readout.remainingText).toBe('0 B free');
    });
});

describe('the bar', () => {
    it('draws the percent consumed while there is room', () => {
        expect(storageReadout(storage()).barPercent).toBe(25);
    });

    it('clamps an overshoot to a full bar', () => {
        expect(
            storageReadout(storage({ usedBytes: 9 * MEGABYTE, percent: 225 }))
                .barPercent,
        ).toBe(100);
    });
});

describe('the tone', () => {
    it('stays muted while the workspace has room to spare', () => {
        const readout = storageReadout(storage());

        expect(readout.toneClass).toBe('text-muted-foreground');
        expect(readout.barColor).toBe('var(--chart-1)');
    });

    it('warns once the workspace is filling up', () => {
        const readout = storageReadout(
            storage({ usedBytes: 3.4 * MEGABYTE, percent: 85 }),
        );

        expect(readout.toneClass).toBe('text-amber-700 dark:text-amber-500');
        expect(readout.barColor).toBe('var(--chart-2)');
    });

    it('turns destructive once the quota is spent', () => {
        const readout = storageReadout(
            storage({ usedBytes: 4 * MEGABYTE, percent: 100 }),
        );

        expect(readout.toneClass).toBe('text-destructive-text');
        expect(readout.barColor).toBe('var(--destructive)');
    });
});
