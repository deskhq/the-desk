<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Check,
    ChevronRight,
    FolderPlus,
    GripVertical,
    MessageSquareText,
    MessagesSquare,
    MoreVertical,
    Pencil,
    Plus,
    Search,
    Trash2,
    UserPlus,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import draggable from 'vuedraggable';
import {
    browse,
    show,
} from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { update as updateChannelPlacement } from '@/actions/App/Http/Controllers/Channels/ChannelPlacementController';
import {
    destroy as destroySection,
    reorder as reorderSections,
    store as storeSection,
    update as updateSection,
} from '@/actions/App/Http/Controllers/Channels/ChannelSectionController';
import { index as searchMessages } from '@/actions/App/Http/Controllers/Channels/SearchController';
import { index as threadsInbox } from '@/actions/App/Http/Controllers/Channels/ThreadsController';
import { update as updateSidebarSections } from '@/actions/App/Http/Controllers/SidebarSectionController';
import ChannelListItem from '@/components/ChannelListItem.vue';
import CreateChannelModal from '@/components/CreateChannelModal.vue';
import CreateTeamModal from '@/components/CreateTeamModal.vue';
import InviteMemberModal from '@/components/InviteMemberModal.vue';
import KeyboardShortcutsModal from '@/components/KeyboardShortcutsModal.vue';
import NavUser from '@/components/NavUser.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import QuickSwitcher from '@/components/QuickSwitcher.vue';
import SettingsNav from '@/components/SettingsNav.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupContent,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
} from '@/components/ui/sidebar';
import { adjacentSlug } from '@/composables/keyboardShortcuts';
import { useChimeNotifications } from '@/composables/useChimeNotifications';
import { useInitials } from '@/composables/useInitials';
import { useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts';
import { useKeyboardShortcutsModal } from '@/composables/useKeyboardShortcutsModal';
import { useSidebarBadges } from '@/composables/useSidebarBadges';
import { useTeamSwitch } from '@/composables/useTeamSwitch';
import { useTimezone } from '@/composables/useTimezone';
import {
    partitionChannels,
    toggleCollapsedSection,
} from '@/lib/channelSections';
import type { SidebarSectionKey } from '@/lib/channelSections';
import type { Channel, ChannelSection } from '@/types/channels';
import type { RoleOption } from '@/types/teams';

const page = usePage();

// The same workspace shell wraps the settings/teams section, but its sidebar
// swaps the channel list for the settings navigation so there is a single
// sidebar rather than a nested one.
const isSettingsSection = computed(() => {
    const component = page.component;

    return component.startsWith('settings/') || component.startsWith('teams/');
});

// Chime for qualifying messages across every channel while the workspace is open.
useChimeNotifications();

// Keep the sidebar unread/mention badges live as messages arrive in channels the
// user is a member of but not currently viewing.
useSidebarBadges();

const currentTeam = computed(() => page.props.currentTeam);
const teams = computed(() => page.props.teams ?? []);
const channels = computed(() => page.props.channels ?? []);
const activeChannelSlug = computed(
    () => (page.props.channel as { slug?: string } | undefined)?.slug ?? null,
);
const pendingInvitations = computed(() => page.props.pendingInvitations ?? []);
const hasUnreadThreads = computed(() => page.props.hasUnreadThreads ?? false);

// The dock header's "invite people" mini-button reuses the member-invite modal;
// the permission and assignable roles ride along on the shared workspace props.
const canInviteToCurrentTeam = computed(
    () => page.props.canInviteToCurrentTeam ?? false,
);
const invitableRoles = computed<RoleOption[]>(
    () => page.props.invitableRoles ?? [],
);
const inviteOpen = ref(false);

// The user's custom sidebar sections for the current team, kept in their
// persisted order.
const customSections = computed<ChannelSection[]>(
    () => page.props.channelSections ?? [],
);

// Local, drag-mutable copies of each sidebar group. vuedraggable writes to these
// arrays as the user drags; they are re-seeded from the shared props whenever the
// server recomputes them (a star toggle, a live badge update, or a persisted
// reorder round-tripping), so the layout follows the user across devices.
const starredList = ref<Channel[]>([]);
const defaultList = ref<Channel[]>([]);
const customGroups = ref<{ section: ChannelSection; channels: Channel[] }[]>(
    [],
);

function syncSidebarGroups(): void {
    const partitioned = partitionChannels(channels.value, customSections.value);
    starredList.value = [...partitioned.starred];
    defaultList.value = [...partitioned.others];
    customGroups.value = partitioned.custom.map((group) => ({
        section: group.section,
        channels: [...group.channels],
    }));
}

watch([channels, customSections], syncSidebarGroups, { immediate: true });

/**
 * Persist a channel's placement: reorder the group it now lives in, and — when
 * `sectionId` is provided — file it under that section (null for the default
 * "Channels" group). Only the shared `channels` prop is reloaded so the sidebar
 * re-partitions in place.
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
                    'Failed to save the sidebar layout. Please try again.',
                );
            },
        },
    );
}

/**
 * Handle a vuedraggable change within one channel group. A `moved` event is a
 * pure reorder (the assignment is left untouched); an `added` event means the
 * channel was dragged in from another group, so it is filed under this group's
 * section as well.
 */
type ChannelDragChange = {
    moved?: { element: Channel };
    added?: { element: Channel };
};

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
 * A reorder within the "Starred" group. Starred channels keep any section
 * assignment, so only their order is persisted.
 */
function onStarredChange(change: ChannelDragChange): void {
    if (change.moved) {
        persistPlacement(change.moved.element, starredList.value, undefined);
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

/**
 * Persist the manual order of the custom sections after a drag.
 */
type ChannelGroup = { section: ChannelSection; channels: Channel[] };

/**
 * The vuedraggable item key for a custom section row.
 */
function sectionKey(group: ChannelGroup): string {
    return group.section.id;
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
                    'Failed to save the section order. Please try again.',
                );
            },
        },
    );
}

// Creating a new section from the inline form under the "Channels" header.
const sectionFormOpen = ref(false);
const newSectionName = ref('');
const sectionNameInput = ref<HTMLInputElement | null>(null);

async function openSectionForm(): Promise<void> {
    sectionFormOpen.value = true;
    await nextTick();
    sectionNameInput.value?.focus();
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
        storeSection({ team: currentTeam.value?.slug ?? '' }).url,
        { name },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['channelSections'],
            onSuccess: () => cancelSectionForm(),
            onError: () => {
                toast.error('Failed to create the section. Please try again.');
            },
        },
    );
}

// Inline renaming of an existing section. The input lives inside the sections
// v-for, so a function ref captures the single mounted instance rather than the
// array a string ref would collect.
const renamingSectionId = ref<string | null>(null);
const renameValue = ref('');
const renameInput = ref<HTMLInputElement | null>(null);

function setRenameInput(el: unknown): void {
    if (el instanceof HTMLInputElement) {
        renameInput.value = el;
    }
}

async function startRename(section: ChannelSection): Promise<void> {
    renamingSectionId.value = section.id;
    renameValue.value = section.name;
    await nextTick();
    renameInput.value?.focus();
    renameInput.value?.select();
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
        updateSection({
            team: currentTeam.value?.slug ?? '',
            section: section.id,
        }).url,
        { name },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['channelSections'],
            onSuccess: () => cancelRename(),
            onError: () => {
                toast.error('Failed to rename the section. Please try again.');
            },
        },
    );
}

function deleteSection(section: ChannelSection): void {
    router.delete(
        destroySection({
            team: currentTeam.value?.slug ?? '',
            section: section.id,
        }).url,
        {
            preserveScroll: true,
            preserveState: true,
            only: ['channels', 'channelSections'],
            onError: () => {
                toast.error('Failed to delete the section. Please try again.');
            },
        },
    );
}

/**
 * Collapse or expand a custom section, persisting the flag (the custom sections
 * carry their own collapse state, unlike the built-in ones). Optimistic, rolling
 * back if the request fails.
 */
function toggleCustomSection(group: {
    section: ChannelSection;
    channels: Channel[];
}): void {
    const previous = group.section.collapsed;
    group.section.collapsed = !previous;

    router.patch(
        updateSection({
            team: currentTeam.value?.slug ?? '',
            section: group.section.id,
        }).url,
        { collapsed: group.section.collapsed },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['channelSections'],
            onError: () => {
                group.section.collapsed = previous;
                toast.error(
                    'Failed to save the sidebar layout. Please try again.',
                );
            },
        },
    );
}

// Which sidebar sections the user has collapsed, seeded from the shared prop and
// kept in sync when the server recomputes it (e.g. after a reload on another
// device). A local ref lets the header toggle feel instant before the persisted
// state round-trips.
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

/**
 * Collapse or expand a sidebar section, persisting the new set so the layout
 * follows the user across reloads and devices. The toggle is optimistic and
 * rolls back if the request fails.
 */
function toggleSection(section: SidebarSectionKey): void {
    const previous = collapsedSections.value;
    const next = toggleCollapsedSection(previous, section);
    collapsedSections.value = next;

    router.patch(
        updateSidebarSections().url,
        { collapsed: next },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['collapsedChannelSections'],
            onError: () => {
                collapsedSections.value = previous;
                toast.error(
                    'Failed to save the sidebar layout. Please try again.',
                );
            },
        },
    );
}

const { getInitials } = useInitials();
const { switchTeam } = useTeamSwitch();

const quickSwitcherOpen = ref(false);
const { isOpen: shortcutsOpen, toggle: toggleShortcuts } =
    useKeyboardShortcutsModal();

/**
 * Jump `delta` channels along the sidebar list from the active one, wrapping at
 * either end. Does nothing until a team and its channels are loaded.
 */
function moveChannel(delta: number): void {
    if (!currentTeam.value) {
        return;
    }

    const slug = adjacentSlug(
        channels.value.map((channel) => channel.slug),
        activeChannelSlug.value,
        delta,
    );

    if (slug) {
        router.visit(show({ team: currentTeam.value.slug, channel: slug }).url);
    }
}

useKeyboardShortcuts({
    'quick-switcher': () =>
        (quickSwitcherOpen.value = !quickSwitcherOpen.value),
    'previous-channel': () => moveChannel(-1),
    'next-channel': () => moveChannel(1),
    'show-shortcuts': () => toggleShortcuts(),
});

const { syncDetectedTimezone } = useTimezone();

onMounted(() => {
    // Lazily pull the (optional) shared invitations so the post-login prompt appears.
    router.reload({ only: ['pendingInvitations'] });

    // Persist the browser's timezone on first login when none is stored yet.
    syncDetectedTimezone();
});
</script>

<template>
    <SidebarProvider
        class="bg-background"
        style="--sidebar-width: calc(272px + 1.75rem)"
    >
        <!-- The dock: a single floating card. Team switching, invite, and
             new-team fold into the workspace header (the vertical team rail is
             gone); on mobile the whole card slides in as the built-in Sheet. -->
        <Sidebar collapsible="offcanvas" variant="floating" class="p-3.5">
            <SidebarHeader
                class="gap-0 border-b border-sidebar-border p-3.5 pb-2.5"
            >
                <div class="flex items-center gap-2">
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                data-test="workspace-switcher"
                                class="-m-1 flex min-w-0 flex-1 items-center gap-2 rounded-[9px] p-1 text-left transition-colors hover:bg-sidebar-accent"
                            >
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-[9px] bg-sidebar-primary text-[11px] font-semibold text-sidebar-primary-foreground"
                                    >{{
                                        getInitials(currentTeam?.name ?? '')
                                    }}</span
                                >
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block truncate text-sm font-semibold text-sidebar-foreground"
                                        >{{
                                            currentTeam?.name ?? 'Select team'
                                        }}</span
                                    >
                                    <span
                                        class="block text-[11px] text-muted-foreground"
                                        >{{ currentTeam?.membersCount ?? 0 }}
                                        {{
                                            (currentTeam?.membersCount ?? 0) ===
                                            1
                                                ? 'member'
                                                : 'members'
                                        }}</span
                                    >
                                </span>
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" class="w-56">
                            <DropdownMenuLabel
                                class="text-xs text-muted-foreground"
                            >
                                Teams
                            </DropdownMenuLabel>
                            <DropdownMenuItem
                                v-for="team in teams"
                                :key="team.id"
                                data-test="team-switcher-item"
                                class="cursor-pointer gap-2"
                                @click="switchTeam(team)"
                            >
                                {{ team.name }}
                                <Check
                                    v-if="team.id === currentTeam?.id"
                                    class="ml-auto size-4"
                                />
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <CreateTeamModal>
                                <DropdownMenuItem
                                    data-test="team-switcher-new-team"
                                    class="cursor-pointer gap-2"
                                    @select.prevent
                                >
                                    <Plus class="size-4" />
                                    <span class="text-muted-foreground"
                                        >New team</span
                                    >
                                </DropdownMenuItem>
                            </CreateTeamModal>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <div class="flex shrink-0 items-center gap-1">
                        <button
                            v-if="canInviteToCurrentTeam"
                            type="button"
                            title="Invite people"
                            data-test="invite-member-trigger"
                            class="flex size-6 items-center justify-center rounded-[7px] border border-sidebar-border text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground"
                            @click="inviteOpen = true"
                        >
                            <UserPlus class="size-3" />
                            <span class="sr-only">Invite people</span>
                        </button>
                        <CreateTeamModal>
                            <button
                                type="button"
                                title="New team"
                                data-test="new-team-trigger"
                                class="flex size-6 items-center justify-center rounded-[7px] border border-dashed border-sidebar-border text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground"
                            >
                                <Plus class="size-3" />
                                <span class="sr-only">New team</span>
                            </button>
                        </CreateTeamModal>
                    </div>
                </div>
            </SidebarHeader>

            <SidebarContent>
                <SettingsNav v-if="isSettingsSection" />
                <template v-else>
                    <div class="px-2 pt-2">
                        <button
                            type="button"
                            data-test="quick-switcher-trigger"
                            class="flex h-8 w-full items-center gap-2 rounded-[9px] bg-muted px-2.5 text-[13px] text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground"
                            @click="quickSwitcherOpen = true"
                        >
                            <Search class="size-[13px] shrink-0" />
                            <span>Jump to…</span>
                            <kbd
                                class="ml-auto font-mono text-[10px] font-semibold tracking-wide text-muted-foreground/70"
                                >⌘K</kbd
                            >
                        </button>
                    </div>
                    <!-- Starred channels, pinned above the main list; the
                             whole section is hidden until the user stars one.
                             Reorderable within itself (its own drag group), but a
                             starred row can't be dragged into a custom section —
                             starring wins, so its move menu stays hidden. -->
                    <SidebarGroup v-if="starredList.length > 0" class="pb-0">
                        <button
                            type="button"
                            data-test="section-toggle-starred"
                            :aria-expanded="!isSectionCollapsed('starred')"
                            class="flex h-7 w-full items-center gap-1 rounded-md px-2 text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground/70 uppercase transition-colors hover:bg-sidebar-accent/40 hover:text-sidebar-foreground"
                            @click="toggleSection('starred')"
                        >
                            <ChevronRight
                                class="size-3 shrink-0 transition-transform"
                                :class="
                                    isSectionCollapsed('starred')
                                        ? ''
                                        : 'rotate-90'
                                "
                            />
                            Starred
                        </button>
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

                    <!-- The user's custom sections, drag-reorderable amongst
                             themselves; each holds a channel list that shares a
                             drag group with the default "Channels" list below, so
                             channels can be dragged across them. -->
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
                            <SidebarGroup
                                class="pb-0"
                                :data-test="`section-custom-${group.section.id}`"
                            >
                                <div
                                    class="group/section flex h-7 w-full items-center gap-1 rounded-md pr-1 pl-2 text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground/70 uppercase transition-colors hover:bg-sidebar-accent/40 hover:text-sidebar-foreground"
                                >
                                    <button
                                        type="button"
                                        :data-test="`section-drag-${group.section.id}`"
                                        :aria-label="`Reorder ${group.section.name}`"
                                        title="Drag to reorder section"
                                        class="section-drag-handle flex size-4 shrink-0 cursor-grab items-center justify-center rounded text-muted-foreground/50 opacity-0 transition group-hover/section:opacity-100 hover:text-sidebar-foreground active:cursor-grabbing"
                                    >
                                        <GripVertical class="size-3" />
                                    </button>
                                    <button
                                        type="button"
                                        :data-test="`section-toggle-custom-${group.section.id}`"
                                        :aria-expanded="
                                            !group.section.collapsed
                                        "
                                        class="flex min-w-0 flex-1 items-center gap-1"
                                        @click="toggleCustomSection(group)"
                                    >
                                        <ChevronRight
                                            class="size-3 shrink-0 transition-transform"
                                            :class="
                                                group.section.collapsed
                                                    ? ''
                                                    : 'rotate-90'
                                            "
                                        />
                                        <input
                                            v-if="
                                                renamingSectionId ===
                                                group.section.id
                                            "
                                            :ref="setRenameInput"
                                            v-model="renameValue"
                                            :data-test="`section-rename-input-${group.section.id}`"
                                            class="w-full min-w-0 rounded-sm border border-sidebar-border bg-sidebar px-1 py-0.5 text-[11px] tracking-normal text-sidebar-foreground normal-case focus:outline-none"
                                            type="text"
                                            maxlength="50"
                                            @click.stop
                                            @keydown.enter.prevent="
                                                renameInput?.blur()
                                            "
                                            @keydown.esc="cancelRename"
                                            @blur="submitRename(group.section)"
                                        />
                                        <span
                                            v-else
                                            class="truncate"
                                            @dblclick.stop="
                                                startRename(group.section)
                                            "
                                            >{{ group.section.name }}</span
                                        >
                                    </button>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <button
                                                type="button"
                                                :data-test="`section-menu-${group.section.id}`"
                                                :aria-label="`Options for ${group.section.name}`"
                                                title="Section options"
                                                class="flex size-5 shrink-0 items-center justify-center rounded text-muted-foreground/60 opacity-0 transition group-hover/section:opacity-100 hover:text-sidebar-foreground focus-visible:opacity-100 data-[state=open]:opacity-100"
                                            >
                                                <MoreVertical
                                                    class="size-3.5"
                                                />
                                            </button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            class="w-40"
                                        >
                                            <DropdownMenuItem
                                                :data-test="`section-rename-${group.section.id}`"
                                                @select="
                                                    startRename(group.section)
                                                "
                                            >
                                                <Pencil class="size-3.5" />
                                                Rename
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                variant="destructive"
                                                :data-test="`section-delete-${group.section.id}`"
                                                @select="
                                                    deleteSection(group.section)
                                                "
                                            >
                                                <Trash2 class="size-3.5" />
                                                Delete
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                                <SidebarGroupContent
                                    v-show="!group.section.collapsed"
                                    :data-test="`section-content-custom-${group.section.id}`"
                                >
                                    <draggable
                                        v-model="group.channels"
                                        :group="{
                                            name: 'sidebar-channels',
                                        }"
                                        handle=".channel-drag-handle"
                                        item-key="id"
                                        tag="ul"
                                        class="flex w-full min-w-0 flex-col gap-1"
                                        :class="
                                            group.channels.length === 0
                                                ? 'min-h-6'
                                                : ''
                                        "
                                        :animation="150"
                                        @change="
                                            (change) =>
                                                onChannelChange(
                                                    change,
                                                    group.channels,
                                                    group.section.id,
                                                )
                                        "
                                    >
                                        <template #item="{ element }">
                                            <ChannelListItem
                                                :channel="element"
                                                :team-slug="
                                                    currentTeam?.slug ?? ''
                                                "
                                                :active-channel-slug="
                                                    activeChannelSlug
                                                "
                                                :sections="customSections"
                                                :current-section-id="
                                                    group.section.id
                                                "
                                                @move="
                                                    (sectionId) =>
                                                        moveChannelToSection(
                                                            element,
                                                            sectionId,
                                                        )
                                                "
                                            />
                                        </template>
                                    </draggable>
                                    <p
                                        v-if="group.channels.length === 0"
                                        class="px-7 pb-1 text-[12px] text-muted-foreground/50 normal-case"
                                    >
                                        Drag channels here
                                    </p>
                                </SidebarGroupContent>
                            </SidebarGroup>
                        </template>
                    </draggable>

                    <!-- The default "Channels" list: unstarred, unassigned
                             channels. Shares a drag group with the custom sections
                             so channels can be dragged in and out. -->
                    <SidebarGroup>
                        <button
                            type="button"
                            data-test="section-toggle-channels"
                            :aria-expanded="!isSectionCollapsed('channels')"
                            class="flex h-7 w-full items-center gap-1 rounded-md px-2 text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground/70 uppercase transition-colors hover:bg-sidebar-accent/40 hover:text-sidebar-foreground"
                            @click="toggleSection('channels')"
                        >
                            <ChevronRight
                                class="size-3 shrink-0 transition-transform"
                                :class="
                                    isSectionCollapsed('channels')
                                        ? ''
                                        : 'rotate-90'
                                "
                            />
                            Channels
                        </button>
                        <CreateChannelModal
                            v-if="currentTeam"
                            :team-slug="currentTeam.slug"
                        >
                            <SidebarGroupAction
                                title="Create channel"
                                data-test="create-channel-trigger"
                                class="top-2 size-5 rounded-md text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                            >
                                <Plus class="size-[13px]" />
                                <span class="sr-only">Create channel</span>
                            </SidebarGroupAction>
                        </CreateChannelModal>
                        <SidebarGroupContent
                            v-show="!isSectionCollapsed('channels')"
                            data-test="section-content-channels"
                        >
                            <draggable
                                v-model="defaultList"
                                :group="{ name: 'sidebar-channels' }"
                                handle=".channel-drag-handle"
                                item-key="id"
                                tag="ul"
                                class="flex w-full min-w-0 flex-col gap-1"
                                :animation="150"
                                @change="
                                    (change) =>
                                        onChannelChange(
                                            change,
                                            defaultList,
                                            null,
                                        )
                                "
                            >
                                <template #item="{ element }">
                                    <ChannelListItem
                                        :channel="element"
                                        :team-slug="currentTeam?.slug ?? ''"
                                        :active-channel-slug="activeChannelSlug"
                                        :sections="customSections"
                                        :current-section-id="null"
                                        @move="
                                            (sectionId) =>
                                                moveChannelToSection(
                                                    element,
                                                    sectionId,
                                                )
                                        "
                                    />
                                </template>
                            </draggable>
                        </SidebarGroupContent>
                    </SidebarGroup>

                    <!-- Create a new custom section. -->
                    <SidebarGroup class="py-0">
                        <SidebarGroupContent>
                            <div v-if="sectionFormOpen" class="px-2">
                                <input
                                    ref="sectionNameInput"
                                    v-model="newSectionName"
                                    data-test="create-section-input"
                                    class="w-full rounded-md border border-sidebar-border bg-sidebar px-2 py-1 text-[13px] text-sidebar-foreground focus:outline-none"
                                    type="text"
                                    maxlength="50"
                                    placeholder="New section name"
                                    @keydown.enter.prevent="
                                        sectionNameInput?.blur()
                                    "
                                    @keydown.esc="cancelSectionForm"
                                    @blur="createSection"
                                />
                            </div>
                            <button
                                v-else
                                type="button"
                                data-test="create-section-trigger"
                                class="flex h-7 w-full items-center gap-1.5 rounded-md px-2 text-[12px] text-muted-foreground transition-colors hover:bg-sidebar-accent/60 hover:text-sidebar-foreground"
                                @click="openSectionForm"
                            >
                                <FolderPlus class="size-3.5" />
                                New section
                            </button>
                        </SidebarGroupContent>
                    </SidebarGroup>

                    <!-- Workspace navigation, always visible regardless of
                             which channel sections are collapsed. -->
                    <SidebarGroup class="pt-0">
                        <SidebarGroupContent>
                            <SidebarMenu>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        as-child
                                        class="h-[30px] gap-2 rounded-[9px] px-2.5 text-[13px] text-muted-foreground hover:bg-sidebar-accent/60"
                                    >
                                        <Link
                                            v-if="currentTeam"
                                            :href="
                                                threadsInbox(currentTeam.slug)
                                                    .url
                                            "
                                            data-test="threads-inbox"
                                        >
                                            <MessagesSquare
                                                class="size-[13px]"
                                            />
                                            <span>Threads</span>
                                            <span
                                                v-if="hasUnreadThreads"
                                                data-test="threads-unread-dot"
                                                aria-hidden="true"
                                                class="ml-auto size-1.5 rounded-full bg-brass"
                                            />
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        as-child
                                        class="h-[30px] gap-2 rounded-[9px] px-2.5 text-[13px] text-muted-foreground hover:bg-sidebar-accent/60"
                                    >
                                        <Link
                                            v-if="currentTeam"
                                            :href="
                                                searchMessages(currentTeam.slug)
                                                    .url
                                            "
                                            data-test="search-messages"
                                        >
                                            <MessageSquareText
                                                class="size-[13px]"
                                            />
                                            <span>Search messages</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        as-child
                                        class="h-[30px] gap-2 rounded-[9px] px-2.5 text-[13px] text-muted-foreground hover:bg-sidebar-accent/60"
                                    >
                                        <Link
                                            v-if="currentTeam"
                                            :href="browse(currentTeam.slug).url"
                                            data-test="browse-channels"
                                        >
                                            <Search class="size-[13px]" />
                                            <span>Browse channels</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                </template>
            </SidebarContent>

            <SidebarFooter class="border-t border-sidebar-border p-2.5">
                <NavUser />
            </SidebarFooter>
        </Sidebar>

        <!-- Main card: fills the screen on mobile, floats on the warm canvas
             (matching the dock) from md up. -->
        <SidebarInset
            class="flex h-svh flex-col overflow-hidden md:my-3.5 md:mr-3.5 md:h-[calc(100svh-1.75rem)] md:rounded-[14px] md:border md:border-border md:bg-card md:shadow-sm"
        >
            <slot />
        </SidebarInset>

        <InviteMemberModal
            v-if="currentTeam && canInviteToCurrentTeam"
            v-model:open="inviteOpen"
            :team="currentTeam"
            :available-roles="invitableRoles"
        />

        <PendingInvitationsModal
            v-if="pendingInvitations.length > 0"
            :invitations="pendingInvitations"
        />

        <QuickSwitcher
            v-if="currentTeam"
            v-model:open="quickSwitcherOpen"
            :channels="channels"
            :team-slug="currentTeam.slug"
        />

        <KeyboardShortcutsModal v-model:open="shortcutsOpen" />
    </SidebarProvider>
</template>
