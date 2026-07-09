import type { Channel, ChannelSection } from '@/types/channels';

/**
 * The built-in sidebar sections whose collapsed state is persisted per user.
 * Keys must match `App\Http\Requests\UpdateSidebarSectionsRequest::SECTIONS`.
 */
export const SIDEBAR_SECTIONS = ['starred', 'channels'] as const;

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
};

/**
 * Split the sidebar's channels into the pinned "Starred" section, the viewer's
 * custom sections, and the default "Channels" list, preserving the incoming
 * order within each group (the server already sorts by position then name).
 *
 * Starring wins over section assignment: a starred channel always renders in
 * "Starred", even when it also carries a `sectionId`. A channel assigned to a
 * section that no longer exists falls back to the default group.
 */
export function partitionChannels(
    channels: Channel[],
    sections: ChannelSection[] = [],
): ChannelSections {
    const starred: Channel[] = [];
    const others: Channel[] = [];
    const bySection = new Map<string, Channel[]>(
        sections.map((section) => [section.id, []]),
    );

    for (const channel of channels) {
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

    return { starred, custom, others };
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
