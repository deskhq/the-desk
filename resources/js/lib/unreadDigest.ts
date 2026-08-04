import type { UnreadCounts, UnreadDigest } from '@/types/unread';

/**
 * What an absent key means. Shared rather than built per lookup so a row that
 * has nothing waiting compares identically on every render.
 */
export const NOTHING_UNREAD: UnreadCounts = Object.freeze({
    unread: 0,
    mention: 0,
});

/**
 * The digest a page carries when it carries none — a guest, or a surface
 * rendered outside any workspace.
 */
export const EMPTY_DIGEST: UnreadDigest = Object.freeze({
    channels: {},
    teams: {},
    threads: false,
});

/**
 * What is waiting in one conversation.
 *
 * The digest names only the channels that hold something, so almost every
 * lookup on an ordinary workspace misses — which is the point: a read
 * conversation costs nothing on the wire and nothing here.
 */
export function channelUnread(
    digest: UnreadDigest,
    channelId: string,
): UnreadCounts {
    return digest.channels[channelId] ?? NOTHING_UNREAD;
}

/**
 * What is waiting in one workspace: the sum of its channels' readings, taken
 * server-side from the same query, so the rail's dot can never disagree with
 * the rows the sidebar shows inside it.
 */
export function workspaceUnread(
    digest: UnreadDigest,
    teamId: string,
): UnreadCounts {
    return digest.teams[teamId] ?? NOTHING_UNREAD;
}
