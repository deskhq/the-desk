import { usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { update as updateSidebarSections } from '@/actions/App/Http/Controllers/SidebarSectionController';
import {
    snapshotRef,
    useOptimisticWrite,
} from '@/composables/useOptimisticWrite';
import { useTranslations } from '@/composables/useTranslations';
import { toggleCollapsedSection } from '@/lib/channelSections';
import type { SidebarSectionKey } from '@/lib/channelSections';
import { COLLAPSED_SECTION_PROPS } from '@/lib/reloadProps';

export interface CollapsedSections {
    /** Whether the given built-in section is currently collapsed. */
    isSectionCollapsed: (section: SidebarSectionKey) => boolean;
    /** Collapse or expand a built-in section, persisting the new set. */
    toggleSection: (section: SidebarSectionKey) => void;
}

/**
 * Which built-in sidebar sections the user has collapsed, seeded from the shared
 * prop and kept in sync when the server recomputes it (e.g. after a reload on
 * another device). A local ref lets the header toggle feel instant before the
 * persisted state round-trips, and the toggle rolls back if the request fails.
 *
 * The custom sections carry their own collapse flag on the section row instead,
 * so they are toggled through {@see useChannelSections} rather than here.
 */
export function useCollapsedSections(): CollapsedSections {
    const page = usePage();
    const { t } = useTranslations();
    const { write } = useOptimisticWrite();

    const collapsedSections = ref<string[]>([
        ...(page.props.collapsedChannelSections ?? []),
    ]);

    watch(
        () => page.props.collapsedChannelSections,
        (value) => {
            collapsedSections.value = [...(value ?? [])];
        },
    );

    function isSectionCollapsed(section: SidebarSectionKey): boolean {
        return collapsedSections.value.includes(section);
    }

    function toggleSection(section: SidebarSectionKey): void {
        const next = toggleCollapsedSection(collapsedSections.value, section);

        write({
            capture: () => snapshotRef(collapsedSections),
            apply: () => {
                collapsedSections.value = next;
            },
            method: 'patch',
            url: updateSidebarSections().url,
            data: { collapsed: next },
            only: COLLAPSED_SECTION_PROPS,
            failure: t('Failed to save the sidebar layout'),
        });
    }

    return { isSectionCollapsed, toggleSection };
}
