<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, nextTick, useTemplateRef, watch } from 'vue';
import draggable from 'vuedraggable';
import ChannelListItem from '@/components/ChannelListItem.vue';
import CustomSectionGroup from '@/components/navigation/CustomSectionGroup.vue';
import DefaultChannelGroup from '@/components/navigation/DefaultChannelGroup.vue';
import DirectMessageGroup from '@/components/navigation/DirectMessageGroup.vue';
import SidebarSectionHeader from '@/components/navigation/SidebarSectionHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { SidebarGroup, SidebarGroupContent } from '@/components/ui/sidebar';
import { useChannelPlacement } from '@/composables/useChannelPlacement';
import { useChannelSections } from '@/composables/useChannelSections';
import { useCollapsedSections } from '@/composables/useCollapsedSections';
import { useQuickSwitcher } from '@/composables/useQuickSwitcher';
import type { ChannelSectionGroup } from '@/lib/channelSections';
import type { RenderedPresence } from '@/lib/presence';

defineProps<{
    /** How a teammate's presence dot should render, for the DM rows. */
    presenceFor: (userId: string) => RenderedPresence;
    /** Whether a teammate is in do-not-disturb, for the DM rows. */
    isDndFor: (userId: string) => boolean;
}>();

defineEmits<{
    /** The user asked to start a new direct message; the shell owns the picker. */
    newMessage: [];
}>();

const page = usePage();

const { isOpen: quickSwitcherOpen } = useQuickSwitcher();

const currentTeam = computed(() => page.props.currentTeam);
const activeChannelSlug = computed(
    () => (page.props.channel as { slug?: string } | undefined)?.slug ?? null,
);

const {
    customSections,
    starredList,
    defaultList,
    directList,
    customGroups,
    onChannelChange,
    onStarredChange,
    moveChannelToSection,
    onSectionReorder,
} = useChannelPlacement();

const { isSectionCollapsed, toggleSection } = useCollapsedSections();

const {
    sectionFormOpen,
    newSectionName,
    sectionFormFocusRequests,
    renamingSectionId,
    renameValue,
    cancelSectionForm,
    createSection,
    startRename,
    cancelRename,
    submitRename,
    deleteSection,
    toggleCustomSection,
} = useChannelSections();

const createSectionInput = useTemplateRef('createSectionInput');

watch(sectionFormFocusRequests, async () => {
    await nextTick();

    // The "+ New" surface the trigger lives on returns focus to it as the menu
    // closes, and that lands after this tick — so the field is taken on the
    // following frame, or the caret would end up back on the "+" instead.
    requestAnimationFrame(() => createSectionInput.value?.$el?.focus());
});

/**
 * Blur the new-section field so its `@blur` handler commits the name — the
 * Enter-to-confirm idiom the inline fields share.
 */
function commitOnEnter(): void {
    createSectionInput.value?.$el?.blur();
}

/** The vuedraggable item key for a custom section row. */
function sectionKey(group: ChannelSectionGroup): string {
    return group.section.id;
}
</script>

<template>
    <!-- The panel renders one destination at a time. The list is conversations
         only — the four utility rows it used to end with are the rail's and the
         tab bar's job. -->
    <nav
        data-test="channels-nav"
        :aria-label="$t('Channels')"
        class="flex min-w-0 flex-col gap-2"
    >
        <div class="px-2 pt-2">
            <Button
                variant="ghost"
                data-test="quick-switcher-trigger"
                class="flex h-9.5 w-full items-center justify-start gap-2 rounded-[10px] bg-muted px-3 text-[13.5px] font-normal text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground md:h-8 md:rounded-[9px] md:px-2.5 md:text-[13px]"
                @click="quickSwitcherOpen = true"
            >
                <Search class="size-3.25 shrink-0" />
                <span>{{ $t('Jump to…') }}</span>
                <kbd
                    class="ml-auto font-mono text-[10px] font-semibold tracking-wide text-muted-foreground"
                    >⌘K</kbd
                >
            </Button>
        </div>
        <!-- Starred channels, pinned above the main list; the whole section is
             hidden until the user stars one. Reorderable within itself (its own
             drag group), but a starred row can't be dragged into a custom
             section — starring wins, so its move menu stays hidden. -->
        <SidebarGroup v-if="starredList.length > 0" class="pb-0">
            <SidebarSectionHeader
                name="starred"
                :label="$t('Starred')"
                :collapsed="isSectionCollapsed('starred')"
                @toggle="toggleSection('starred')"
            />
            <SidebarGroupContent
                v-show="!isSectionCollapsed('starred')"
                data-test="section-content-starred"
            >
                <draggable
                    v-model="starredList"
                    :group="{ name: 'starred' }"
                    handle=".channel-drag-handle"
                    item-key="id"
                    tag="ul"
                    class="flex w-full min-w-0 flex-col gap-1"
                    :animation="150"
                    @change="onStarredChange"
                >
                    <template #item="{ element }">
                        <ChannelListItem
                            :channel="element"
                            :team-slug="currentTeam?.slug ?? ''"
                            :active-channel-slug="activeChannelSlug"
                        />
                    </template>
                </draggable>
            </SidebarGroupContent>
        </SidebarGroup>

        <!-- The user's custom sections, drag-reorderable amongst themselves;
             each holds a channel list that shares a drag group with the default
             "Channels" list below, so channels can be dragged across them. -->
        <draggable
            v-if="customGroups.length > 0"
            v-model="customGroups"
            :group="{ name: 'sections' }"
            handle=".section-drag-handle"
            :item-key="sectionKey"
            tag="div"
            :animation="150"
            @change="onSectionReorder"
        >
            <template #item="{ element: group }">
                <CustomSectionGroup
                    v-model:channels="group.channels"
                    v-model:rename-value="renameValue"
                    :section="group.section"
                    :sections="customSections"
                    :team-slug="currentTeam?.slug ?? ''"
                    :active-channel-slug="activeChannelSlug"
                    :renaming="renamingSectionId === group.section.id"
                    @toggle="toggleCustomSection(group)"
                    @rename-start="startRename(group.section)"
                    @rename-cancel="cancelRename"
                    @rename-commit="submitRename(group.section)"
                    @delete="deleteSection(group.section)"
                    @move="moveChannelToSection"
                    @change="
                        (change) =>
                            onChannelChange(
                                change,
                                group.channels,
                                group.section.id,
                            )
                    "
                />
            </template>
        </draggable>

        <!-- The default "Channels" list: unstarred, unassigned channels. Shares
             a drag group with the custom sections so channels can be dragged in
             and out. -->
        <DefaultChannelGroup
            v-model:channels="defaultList"
            :sections="customSections"
            :team-slug="currentTeam?.slug ?? ''"
            :active-channel-slug="activeChannelSlug"
            :collapsed="isSectionCollapsed('channels')"
            @toggle="toggleSection('channels')"
            @move="moveChannelToSection"
            @change="(change) => onChannelChange(change, defaultList, null)"
        />

        <DirectMessageGroup
            :channels="directList"
            :team-slug="currentTeam?.slug ?? ''"
            :active-channel-slug="activeChannelSlug"
            :collapsed="isSectionCollapsed('direct')"
            :presence-for="presenceFor"
            :is-dnd-for="isDndFor"
            @toggle="toggleSection('direct')"
            @new-message="$emit('newMessage')"
        />

        <!-- Naming a new custom section. The row that used to stand here is now
             the third row of "+ New"; only the field it opens still belongs at
             the foot of the list, where the section will appear. -->
        <SidebarGroup v-if="sectionFormOpen" class="py-0">
            <SidebarGroupContent>
                <div class="px-2">
                    <Input
                        ref="createSectionInput"
                        v-model="newSectionName"
                        data-test="create-section-input"
                        class="h-8 w-full rounded-md border-sidebar-border bg-sidebar px-2 py-1 text-base text-sidebar-foreground md:text-[13px] dark:bg-sidebar"
                        type="text"
                        maxlength="50"
                        :placeholder="$t('New section name')"
                        @keydown.enter.prevent="commitOnEnter"
                        @keydown.esc="cancelSectionForm"
                        @blur="createSection"
                    />
                </div>
            </SidebarGroupContent>
        </SidebarGroup>
    </nav>
</template>
