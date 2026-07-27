import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { update as updateChannelPlacement } from '@/actions/App/Http/Controllers/Channels/ChannelPlacementController';
import { reorder as reorderSections } from '@/actions/App/Http/Controllers/Channels/ChannelSectionController';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import { partitionChannels } from '@/lib/channelSections';
import type { ChannelSectionGroup } from '@/lib/channelSections';
import type { Channel, ChannelSection } from '@/types/channels';

/**
 * A vuedraggable change within one channel group. A `moved` event is a pure
 * reorder (the assignment is left untouched); an `added` event means the channel
 * was dragged in from another group.
 */
export type ChannelDragChange = {
    moved?: { element: Channel };
    added?: { element: Channel };
};

export interface ChannelPlacement {
    /** The viewer's custom sections, in their persisted order. */
    customSections: ComputedRef<ChannelSection[]>;
    /** Channels the viewer has starred, pinned above every other group. */
    starredList: Ref<Channel[]>;
    /** Unstarred, unassigned channels — the default "Channels" group. */
    defaultList: Ref<Channel[]>;
    /** Direct messages, ordered by recent activity. */
    directList: Ref<Channel[]>;
    /** Each custom section paired with the channels filed under it. */
    customGroups: Ref<ChannelSectionGroup[]>;
    /** Persist a drag within one channel group. */
    onChannelChange: (
        change: ChannelDragChange,
        list: Channel[],
        sectionId: string | null,
    ) => void;
    /** Persist a reorder within the "Starred" group. */
    onStarredChange: (change: ChannelDragChange) => void;
    /** File a channel under a section (or the default group) from its menu. */
    moveChannelToSection: (channel: Channel, sectionId: string | null) => void;
    /** Persist the manual order of the custom sections after a drag. */
    onSectionReorder: () => void;
}

/**
 * The sidebar's channel groups and everything that writes to them.
 *
 * The four group arrays are returned as the refs this composable owns rather
 * than as computed projections, because vuedraggable binds them with `v-model`
 * and writes to them as the user drags. They are re-seeded from the shared props
 * whenever the server recomputes them (a star toggle, a live badge update, or a
 * persisted reorder round-tripping), so the layout follows the user across
 * devices — and re-seeded again on the error paths, to drop a drag the server
 * refused.
 */
export function useChannelPlacement(): ChannelPlacement {
    const page = usePage();
    const { t } = useTranslations();
    const toast = useToast();

    const currentTeam = computed(() => page.props.currentTeam);
    const channels = computed(() => page.props.channels ?? []);
    const customSections = computed<ChannelSection[]>(
        () => page.props.channelSections ?? [],
    );

    const starredList = ref<Channel[]>([]);
    const defaultList = ref<Channel[]>([]);
    const directList = ref<Channel[]>([]);
    const customGroups = ref<ChannelSectionGroup[]>([]);

    function syncSidebarGroups(): void {
        const partitioned = partitionChannels(
            channels.value,
            customSections.value,
        );
        starredList.value = [...partitioned.starred];
        defaultList.value = [...partitioned.others];
        directList.value = [...partitioned.direct];
        customGroups.value = partitioned.custom.map((group) => ({
            section: group.section,
            channels: [...group.channels],
        }));
    }

    watch([channels, customSections], syncSidebarGroups, { immediate: true });

    /**
     * Persist a channel's placement: reorder the group it now lives in, and —
     * when `sectionId` is provided — file it under that section (null for the
     * default "Channels" group). Only the shared `channels` prop is reloaded so
     * the sidebar re-partitions in place.
     */
    function persistPlacement(
        channel: Channel,
        orderedList: Channel[],
        sectionId: string | null | undefined,
    ): void {
        const payload: { ordered_ids: string[]; section_id?: string | null } = {
            ordered_ids: orderedList.map((entry) => entry.id),
        };

        if (sectionId !== undefined) {
            payload.section_id = sectionId;
        }

        router.patch(
            updateChannelPlacement({
                team: currentTeam.value?.slug ?? '',
                channel: channel.slug,
            }).url,
            payload,
            {
                preserveScroll: true,
                preserveState: true,
                only: ['channels'],
                onError: () => {
                    syncSidebarGroups();
                    toast.error(
                        t(
                            'Failed to save the sidebar layout. Please try again.',
                        ),
                    );
                },
            },
        );
    }

    function onChannelChange(
        change: ChannelDragChange,
        list: Channel[],
        sectionId: string | null,
    ): void {
        if (change.added) {
            persistPlacement(change.added.element, list, sectionId);
        } else if (change.moved) {
            persistPlacement(change.moved.element, list, undefined);
        }
    }

    /**
     * Starred channels keep any section assignment, so only their order is
     * persisted.
     */
    function onStarredChange(change: ChannelDragChange): void {
        if (change.moved) {
            persistPlacement(
                change.moved.element,
                starredList.value,
                undefined,
            );
        }
    }

    /**
     * File a channel under a section (or the default group) from its kebab menu,
     * appending it to the end of the target group's current order.
     */
    function moveChannelToSection(
        channel: Channel,
        sectionId: string | null,
    ): void {
        const target =
            sectionId === null
                ? defaultList.value
                : (customGroups.value.find(
                      (group) => group.section.id === sectionId,
                  )?.channels ?? []);

        persistPlacement(channel, [...target, channel], sectionId);
    }

    function onSectionReorder(): void {
        router.patch(
            reorderSections({ team: currentTeam.value?.slug ?? '' }).url,
            { sections: customGroups.value.map((group) => group.section.id) },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['channelSections'],
                onError: () => {
                    syncSidebarGroups();
                    toast.error(
                        t(
                            'Failed to save the section order. Please try again.',
                        ),
                    );
                },
            },
        );
    }

    return {
        customSections,
        starredList,
        defaultList,
        directList,
        customGroups,
        onChannelChange,
        onStarredChange,
        moveChannelToSection,
        onSectionReorder,
    };
}
