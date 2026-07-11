import type { Channel, ChannelSection } from '@/types/channels';

/**
 * The built-in sidebar sections whose collapsed state is persisted per user.
 * Keys must match `App\Http\Requests\UpdateSidebarSectionsRequest::SECTIONS`.
 */
export const SIDEBAR_SECTIONS = ['starred', 'channels', 'direct'] as const;

export type SidebarSectionKey = (typeof SIDEBAR_SECTIONS)[number];

/** A custom section paired with the channels the viewer filed under it. */
export type ChannelSectionGroup = {
    section: ChannelSection;
    channels: Channel[];
};

export type ChannelSections = {
    /** Channels the viewer has starred, pinned above every other group. */
    starred: Channel[];
    /** The viewer's custom sections, in their persisted order. */
    custom: ChannelSectionGroup[];
    /** Unstarred, unassigned channels — the default "Channels" group. */
    others: Channel[];
    /**
     * Direct messages, rendered in their own fixed "Direct messages" group,
     * ordered by most-recent activity. DMs never file into the star/section
     * system, so they are pulled out here before any other bucketing.
     */
    direct: Channel[];
};

/**
 * Split the sidebar's channels into the pinned "Starred" section, the viewer's
 * custom sections, and the default "Channels" list, preserving the incoming
 * order within each group (the server already sorts by position then name).
 *
 * Starring wins over section assignment: a starred channel always renders in
 * "Starred", even when it also carries a `sectionId`. A channel assigned to a
 * section that no longer exists falls back to the default group.
 *
 * Direct messages are pulled out first — they always render in the dedicated
 * "Direct messages" group (ordered by most-recent activity), never in the
 * star/section system.
 */
export function partitionChannels(
    channels: Channel[],
    sections: ChannelSection[] = [],
): ChannelSections {
    const starred: Channel[] = [];
    const others: Channel[] = [];
    const direct: Channel[] = [];
    const bySection = new Map<string, Channel[]>(
        sections.map((section) => [section.id, []]),
    );

    for (const channel of channels) {
        if (channel.isDirect) {
            direct.push(channel);
            continue;
        }

        if (channel.starred) {
            starred.push(channel);
            continue;
        }

        const bucket =
            channel.sectionId != null
                ? bySection.get(channel.sectionId)
                : undefined;

        if (bucket) {
            bucket.push(channel);
        } else {
            others.push(channel);
        }
    }

    const custom = sections.map((section) => ({
        section,
        channels: bySection.get(section.id) ?? [],
    }));

    // Most-recent activity first; a DM with no activity timestamp sorts last.
    direct.sort(
        (a, b) =>
            activityValue(b.lastActivityAt) - activityValue(a.lastActivityAt),
    );

    return { starred, custom, others, direct };
}

/**
 * A sortable numeric value for a DM's last-activity timestamp; a missing
 * timestamp sorts oldest (to the bottom of the group).
 */
function activityValue(timestamp: string | null): number {
    return timestamp ? Date.parse(timestamp) : 0;
}

/**
 * Toggle a section key within the collapsed set, returning a new array. Adding a
 * key collapses the section; removing it expands the section. Unknown keys are
 * left untouched so a stale value never blocks the toggle.
 */
export function toggleCollapsedSection(
    collapsed: readonly string[],
    key: SidebarSectionKey,
): string[] {
    return collapsed.includes(key)
        ? collapsed.filter((section) => section !== key)
        : [...collapsed, key];
}
