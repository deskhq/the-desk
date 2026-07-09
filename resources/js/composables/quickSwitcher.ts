import type { Channel } from '@/types/channels';

/**
 * The minimal shape the ranking cares about. Keeping it structural lets the
 * scorer be unit-tested with plain objects while the component passes real
 * {@link Channel} records.
 */
export type RankableChannel = Pick<Channel, 'name'>;

const WORD_BOUNDARY = /[^a-z0-9]+/;

/**
 * Measure how tightly `needle` appears as an in-order subsequence of
 * `haystack`, returning the number of characters skipped between the first and
 * last matched positions (0 = contiguous). Returns `null` when `haystack` does
 * not contain the subsequence at all.
 */
function subsequenceSpread(haystack: string, needle: string): number | null {
    let firstIndex = -1;
    let lastIndex = -1;
    let cursor = 0;

    for (let i = 0; i < haystack.length && cursor < needle.length; i++) {
        if (haystack[i] === needle[cursor]) {
            if (firstIndex === -1) {
                firstIndex = i;
            }

            lastIndex = i;
            cursor++;
        }
    }

    if (cursor < needle.length) {
        return null;
    }

    return lastIndex - firstIndex - (needle.length - 1);
}

/**
 * Score a channel name against a query. Higher is a better match; `null` means
 * no match at all. Tiers, best to worst: exact, prefix, word-boundary prefix,
 * contiguous substring, in-order subsequence. Within the prefix and substring
 * tiers, tighter matches (shorter remainder / earlier position) score higher.
 */
export function scoreChannelName(name: string, query: string): number | null {
    const haystack = name.toLowerCase();
    const needle = query.toLowerCase();

    if (needle === '') {
        return 0;
    }

    if (haystack === needle) {
        return 1000;
    }

    if (haystack.startsWith(needle)) {
        return 800 - (haystack.length - needle.length);
    }

    if (haystack.split(WORD_BOUNDARY).some((word) => word.startsWith(needle))) {
        return 600;
    }

    const substringIndex = haystack.indexOf(needle);

    if (substringIndex !== -1) {
        return 400 - substringIndex;
    }

    const spread = subsequenceSpread(haystack, needle);

    if (spread !== null) {
        return 200 - spread;
    }

    return null;
}

/**
 * Filter and rank channels for the quick switcher. A leading `#` and
 * surrounding whitespace are stripped so typing `#gen` behaves like `gen`. An
 * empty query returns every channel in alphabetical order; otherwise only
 * matches are returned, best score first, ties broken alphabetically.
 */
export function rankChannels<T extends RankableChannel>(
    channels: readonly T[],
    query: string,
): T[] {
    const normalizedQuery = query.trim().replace(/^#+/, '');

    return channels
        .map((channel) => ({
            channel,
            score: scoreChannelName(channel.name, normalizedQuery),
        }))
        .filter(
            (scored): scored is { channel: T; score: number } =>
                scored.score !== null,
        )
        .sort(
            (a, b) =>
                b.score - a.score ||
                a.channel.name.localeCompare(b.channel.name),
        )
        .map((scored) => scored.channel);
}
