import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, toRef } from 'vue';
import type { Ref } from 'vue';
import {
    destroy as destroySection,
    store as storeSection,
    update as updateSection,
} from '@/actions/App/Http/Controllers/Channels/ChannelSectionController';
import {
    snapshotRef,
    useOptimisticWrite,
} from '@/composables/useOptimisticWrite';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import type { ChannelSectionGroup } from '@/lib/channelSections';
import { CHANNEL_LIST_PROPS, CHANNEL_SECTION_PROPS } from '@/lib/reloadProps';
import type { ChannelSection } from '@/types/channels';

/**
 * Creating and renaming both happen in an inline field, and the state driving
 * them lives at module scope rather than per-caller: the "+ New" menu that opens
 * the create form sits in the dock header, outside the panel that renders the
 * field. That is {@see useDialog}'s pattern, and it matches the lifetime
 * the state had before the extraction — `MainLayout` is a persistent Inertia
 * layout, so its own refs already survived every channel and team switch.
 */
const sectionFormOpen = ref(false);
const newSectionName = ref('');

/**
 * Bumped every time the create form is asked to open, including while it already
 * is. The field lives in the panel, which takes focus off this rather than off
 * `sectionFormOpen`: reopening "+ New" over a showing form has always re-taken
 * the field, and a plain boolean watcher would not fire for it.
 */
const sectionFormFocusRequests = ref(0);

const renamingSectionId = ref<string | null>(null);
const renameValue = ref('');

export interface ChannelSections {
    /** Whether the inline "new section" field is showing. */
    sectionFormOpen: Ref<boolean>;
    /** The name being typed into the inline "new section" field. */
    newSectionName: Ref<string>;
    /** Focus token for the "new section" field; see its declaration. */
    sectionFormFocusRequests: Ref<number>;
    /** The section whose name is being edited inline, if any. */
    renamingSectionId: Ref<string | null>;
    /** The name being typed into the inline rename field. */
    renameValue: Ref<string>;
    /** Show the inline "new section" field and ask the panel for focus. */
    openSectionForm: () => void;
    /** Discard the inline "new section" field and anything typed into it. */
    cancelSectionForm: () => void;
    /** Create the section named in the inline field, if it names one. */
    createSection: () => void;
    /** Begin renaming a section, seeding the editor with its current name. */
    startRename: (section: ChannelSection) => void;
    /** Abandon an inline rename, leaving the section's name as it was. */
    cancelRename: () => void;
    /** Commit an inline rename, if it changed the name to a non-empty one. */
    submitRename: (section: ChannelSection) => void;
    /** Delete a section; the server unfiles its channels to the default group. */
    deleteSection: (section: ChannelSection) => void;
    /** Collapse or expand a custom section, persisting the flag optimistically. */
    toggleCustomSection: (group: ChannelSectionGroup) => void;
}

/**
 * Section CRUD for the conversation list: the inline create form, the inline
 * rename, deletion, and the per-section collapse flag.
 */
export function useChannelSections(): ChannelSections {
    const page = usePage();
    const { t } = useTranslations();
    const toast = useToast();
    const { write } = useOptimisticWrite();

    const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

    function openSectionForm(): void {
        sectionFormOpen.value = true;
        sectionFormFocusRequests.value += 1;
    }

    function cancelSectionForm(): void {
        sectionFormOpen.value = false;
        newSectionName.value = '';
    }

    function createSection(): void {
        const name = newSectionName.value.trim();

        if (name === '') {
            cancelSectionForm();

            return;
        }

        router.post(
            storeSection({ team: teamSlug.value }).url,
            { name },
            {
                preserveScroll: true,
                preserveState: true,
                only: CHANNEL_SECTION_PROPS,
                onSuccess: () => cancelSectionForm(),
                onError: () => {
                    toast.error(t('Failed to create the section'));
                },
            },
        );
    }

    function startRename(section: ChannelSection): void {
        renamingSectionId.value = section.id;
        renameValue.value = section.name;
    }

    function cancelRename(): void {
        renamingSectionId.value = null;
        renameValue.value = '';
    }

    function submitRename(section: ChannelSection): void {
        const name = renameValue.value.trim();

        if (name === '' || name === section.name) {
            cancelRename();

            return;
        }

        router.patch(
            updateSection({ team: teamSlug.value, section: section.id }).url,
            { name },
            {
                preserveScroll: true,
                preserveState: true,
                only: CHANNEL_SECTION_PROPS,
                onSuccess: () => cancelRename(),
                onError: () => {
                    toast.error(t('Failed to rename the section'));
                },
            },
        );
    }

    function deleteSection(section: ChannelSection): void {
        router.delete(
            destroySection({ team: teamSlug.value, section: section.id }).url,
            {
                preserveScroll: true,
                preserveState: true,
                only: [...CHANNEL_LIST_PROPS, ...CHANNEL_SECTION_PROPS],
                onError: () => {
                    toast.error(t('Failed to delete the section'));
                },
            },
        );
    }

    function toggleCustomSection(group: ChannelSectionGroup): void {
        // The flag lives on the section row rather than in a ref of its own, so
        // the snapshot is taken through a writable ref into that property.
        const collapsed = toRef(group.section, 'collapsed');

        write({
            capture: () => snapshotRef(collapsed),
            apply: () => {
                collapsed.value = !collapsed.value;
            },
            method: 'patch',
            url: updateSection({
                team: teamSlug.value,
                section: group.section.id,
            }).url,
            data: () => ({ collapsed: collapsed.value }),
            only: CHANNEL_SECTION_PROPS,
            failure: t('Failed to save the sidebar layout'),
        });
    }

    return {
        sectionFormOpen,
        newSectionName,
        sectionFormFocusRequests,
        renamingSectionId,
        renameValue,
        openSectionForm,
        cancelSectionForm,
        createSection,
        startRename,
        cancelRename,
        submitRename,
        deleteSection,
        toggleCustomSection,
    };
}
