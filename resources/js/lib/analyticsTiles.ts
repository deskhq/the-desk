import { translate } from '@/lib/i18n';
import { formatNumber } from '@/lib/numbers';
import type { WorkspaceAnalytics } from '@/types/teams';

/** One headline tile on the analytics dashboard, already formatted for display. */
export type AnalyticsTile = {
    key: string;
    label: string;
    value: string;
    meta: string;
    /** The raw change, which decides the arrow and the tone; absent for a count. */
    delta: number | null;
    deltaText: string;
};

/**
 * A concise "±N vs previous window" line for a tile's absolute change.
 */
export function deltaVsPrevious(delta: number | null, days: number): string {
    if (delta === null || delta === 0) {
        return translate('No change vs previous :days days', { days });
    }

    const signed = delta > 0 ? `+${delta}` : `${delta}`;

    return translate(':change vs previous :days days', {
        change: signed,
        days,
    });
}

/**
 * The same comparison line, expressed as a percentage of the previous window.
 */
export function percentVsPrevious(
    percent: number | null,
    days: number,
): string {
    if (percent === null) {
        return translate('No previous activity');
    }

    if (percent === 0) {
        return translate('No change vs previous :days days', { days });
    }

    const signed = percent > 0 ? `+${percent}%` : `${percent}%`;

    return translate(':change vs previous :days days', {
        change: signed,
        days,
    });
}

/**
 * Tailwind tone for a delta line: green up, amber down, muted otherwise.
 */
export function toneClass(delta: number | null): string {
    if (delta === null || delta === 0) {
        return 'text-muted-foreground';
    }

    return delta > 0
        ? 'text-emerald-700 dark:text-emerald-500'
        : 'text-amber-700 dark:text-amber-500';
}

/**
 * The headline tiles, each mapping the raw stat to display copy. Reads the
 * reactive catalog through `translate`, so a caller computing this re-runs when
 * the language is switched.
 */
export function analyticsTiles(analytics: WorkspaceAnalytics): AnalyticsTile[] {
    const a = analytics;

    return [
        {
            key: 'members',
            label: translate('Active members'),
            value: formatNumber(a.activeMembers.value),
            meta: translate('of :count', { count: a.activeMembers.total ?? 0 }),
            delta: a.activeMembers.delta,
            deltaText: deltaVsPrevious(a.activeMembers.delta, a.days),
        },
        {
            key: 'perDay',
            label: translate('Messages / day'),
            value: formatNumber(a.messagesPerDay.value),
            meta: translate('avg'),
            delta: a.messagesPerDay.deltaPercent,
            deltaText: percentVsPrevious(a.messagesPerDay.deltaPercent, a.days),
        },
        {
            key: 'sent',
            label: translate('Messages sent'),
            value: formatNumber(a.messagesSent.value),
            meta: translate(':days days', { days: a.days }),
            delta: null,
            deltaText: translate(':count in threads', {
                count: formatNumber(a.messagesSent.secondary ?? 0),
            }),
        },
        {
            key: 'channels',
            label: translate('Active channels'),
            value: formatNumber(a.activeChannels.value),
            meta: translate('of :count', {
                count: a.activeChannels.total ?? 0,
            }),
            delta: a.activeChannels.delta,
            deltaText: deltaVsPrevious(a.activeChannels.delta, a.days),
        },
    ];
}
