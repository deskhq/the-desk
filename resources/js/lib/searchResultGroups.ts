import { translate } from '@/lib/i18n';
import type { MessageSearchResult } from '@/types';

/**
 * A recency bucket of search results, in display order. `key` is stable for
 * `v-for`; `label` is the localized header ("Today", "Yesterday", "Last week",
 * or an older month like "March").
 */
export type SearchResultGroup = {
    key: string;
    label: string;
    results: MessageSearchResult[];
};

/**
 * The whole-day index of a date, so two timestamps on the same calendar day
 * compare equal regardless of clock time. Mirrors the local-day convention of
 * {@link formatDayLabel} rather than the viewer's stored zone.
 */
function dayIndex(date: Date): number {
    return Math.floor(
        new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime() /
            86_400_000,
    );
}

/**
 * Bucket recency-ordered results (newest first) into date groups: Today,
 * Yesterday, Last week (2–7 days ago), then one group per older month. Insertion
 * order is preserved, so the groups stay newest-first and each keeps its results'
 * order. `now` is injectable for testing.
 */
export function groupSearchResults(
    results: MessageSearchResult[],
    now: Date = new Date(),
): SearchResultGroup[] {
    const todayIndex = dayIndex(now);
    const groups = new Map<string, SearchResultGroup>();

    for (const result of results) {
        const date = new Date(result.message.createdAt);
        const { key, label } = bucketFor(date, todayIndex, now);

        const group = groups.get(key);

        if (group === undefined) {
            groups.set(key, { key, label, results: [result] });
        } else {
            group.results.push(result);
        }
    }

    return [...groups.values()];
}

/**
 * The bucket key and localized label a single result falls into.
 */
function bucketFor(
    date: Date,
    todayIndex: number,
    now: Date,
): { key: string; label: string } {
    const distance = todayIndex - dayIndex(date);

    if (distance <= 0) {
        return { key: 'today', label: translate('Today') };
    }

    if (distance === 1) {
        return { key: 'yesterday', label: translate('Yesterday') };
    }

    if (distance <= 6) {
        return { key: 'last-week', label: translate('Last week') };
    }

    return {
        key: `month-${date.getFullYear()}-${date.getMonth()}`,
        label: date.toLocaleDateString(undefined, {
            month: 'long',
            year:
                date.getFullYear() === now.getFullYear() ? undefined : 'numeric',
        }),
    };
}
