import { formatFileSize } from '@/lib/attachments';
import { translate } from '@/lib/i18n';
import type { TeamStorage } from '@/types/teams';

/** The share of the quota at which the read-out starts warning. */
const WARN_PERCENT = 80;

/** The storage read-out, already formatted for display. */
export type StorageReadout = {
    /** The percent consumed, clamped to what the bar can draw. */
    barPercent: number;
    /** Tailwind tone for the percent and remainder lines. */
    toneClass: string;
    /** The bar fill, deepening as the workspace fills up. */
    barColor: string;
    /** "25% used" — the headline figure, uncapped. */
    percentText: string;
    /** "1 MB of 4 MB" — the raw sizes behind the percentage. */
    sizeText: string;
    /** "3 MB free", or how far past the quota the workspace already is. */
    remainingText: string;
};

/**
 * Map a workspace's storage usage onto its display copy and tone. Reads the
 * reactive catalog through `translate`, so a caller computing this re-runs when
 * the language is switched.
 */
export function storageReadout(storage: TeamStorage): StorageReadout {
    const { usedBytes, quotaBytes, percent } = storage;
    const overspill = usedBytes - quotaBytes;

    return {
        barPercent: Math.min(100, Math.max(0, percent)),
        toneClass: toneClass(percent),
        barColor: barColor(percent),
        percentText: translate(':percent% used', { percent }),
        sizeText: translate(':used of :quota', {
            used: formatFileSize(usedBytes),
            quota: formatFileSize(quotaBytes),
        }),
        remainingText:
            overspill > 0
                ? translate(':size over the limit', {
                      size: formatFileSize(overspill),
                  })
                : translate(':size free', {
                      size: formatFileSize(Math.abs(overspill)),
                  }),
    };
}

/**
 * Tailwind tone for the read-out: muted with room to spare, amber once the
 * workspace is filling up, and the destructive text token once it is full.
 */
function toneClass(percent: number): string {
    if (percent >= 100) {
        return 'text-destructive-text';
    }

    return percent >= WARN_PERCENT
        ? 'text-amber-700 dark:text-amber-500'
        : 'text-muted-foreground';
}

/**
 * The bar fill for a usage level, drawn from the chart palette so it sits with
 * the rest of the dashboard, and switching to the destructive token once the
 * quota is spent.
 */
function barColor(percent: number): string {
    if (percent >= 100) {
        return 'var(--destructive)';
    }

    return percent >= WARN_PERCENT ? 'var(--chart-2)' : 'var(--chart-1)';
}
