<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    AlarmClock,
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
import {
    destroy as destroyReminder,
    destroyAll as clearRemindersAction,
    store as storeReminder,
} from '@/actions/App/Http/Controllers/Channels/MessageReminderController';
import { index as searchMessages } from '@/actions/App/Http/Controllers/Channels/SearchController';
import { index as threadsInbox } from '@/actions/App/Http/Controllers/Channels/ThreadsController';
import { update as updateSidebarSections } from '@/actions/App/Http/Controllers/SidebarSectionController';
import ChannelListItem from '@/components/ChannelListItem.vue';
import CreateChannelModal from '@/components/CreateChannelModal.vue';
import CreateTeamModal from '@/components/CreateTeamModal.vue';
import DirectMessageListItem from '@/components/DirectMessageListItem.vue';
import InviteMemberModal from '@/components/InviteMemberModal.vue';
import KeyboardShortcutsModal from '@/components/KeyboardShortcutsModal.vue';
import NavUser from '@/components/NavUser.vue';
import NewDirectMessageModal from '@/components/NewDirectMessageModal.vue';
import OnboardingTour from '@/components/OnboardingTour.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import QuickSwitcher from '@/components/QuickSwitcher.vue';
import ReminderNudge from '@/components/ReminderNudge.vue';
import RemindersDialog from '@/components/RemindersDialog.vue';
import SettingsNav from '@/components/SettingsNav.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
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
import { Toaster } from '@/components/ui/sonner';
import { adjacentSlug } from '@/composables/keyboardShortcuts';
import { useChimeNotifications } from '@/composables/useChimeNotifications';
import { useInitials } from '@/composables/useInitials';
import { useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts';
import { useKeyboardShortcutsModal } from '@/composables/useKeyboardShortcutsModal';
import { useMessageReminders } from '@/composables/useMessageReminders';
import { useNewDirectMessages } from '@/composables/useNewDirectMessages';
import {
    shouldAutoStartTour,
    useOnboardingTour,
} from '@/composables/useOnboardingTour';
import { useSidebarBadges } from '@/composables/useSidebarBadges';
import { useTeamPresence } from '@/composables/useTeamPresence';
import { useTeamSwitch } from '@/composables/useTeamSwitch';
import { useTimezone } from '@/composables/useTimezone';
import { useTranslations } from '@/composables/useTranslations';
import {
    partitionChannels,
    toggleCollapsedSection,
} from '@/lib/channelSections';
import type { SidebarSectionKey } from '@/lib/channelSections';
import type { Channel, ChannelSection } from '@/types/channels';
import type { MessageReminder } from '@/types/messages';
import type { RoleOption } from '@/types/teams';

const page = usePage();

const { t } = useTranslations();

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

// Surface a brand-new direct message in the sidebar the moment someone messages
// the viewer for the first time, without a manual reload.
useNewDirectMessages();

// Slide in a nudge the moment a message reminder comes due.
useMessageReminders();

const currentTeam = computed(() => page.props.currentTeam);
const teams = computed(() => page.props.teams ?? []);
const channels = computed(() => page.props.channels ?? []);
const currentUserId = computed(() => String(page.props.auth.user.id));
// The current team's members, feeding the DM entry points (people picker + ⌘K).
const teamMembers = computed(() => page.props.teamMembers ?? []);

// Online roster for the current team, driving the presence dot on each DM row.
const { onlineIds } = useTeamPresence(() => currentTeam.value?.id);

// The "New message" people picker opened from the "Direct messages" header.
const newDmOpen = ref(false);
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
// Direct messages, ordered by recent activity (the partitioner sorts them). Not
// drag-mutable — DMs never file into sections — so this is a plain projection.
const directList = ref<Channel[]>([]);
const customGroups = ref<{ section: ChannelSection; channels: Channel[] }[]>(
    [],
);

function syncSidebarGroups(): void {
    const partitioned = partitionChannels(channels.value, customSections.value);
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
                    t('Failed to save the sidebar layout. Please try again.'),
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
                    t('Failed to save the section order. Please try again.'),
                );
            },
        },
    );
}

/**
 * Focus (and optionally select) the inline sidebar input identified by its
 * `data-test`. The rename / new-section fields render shadcn `<Input>`
 * components, so their DOM node is resolved by selector once mounted rather
 * than through a component template ref (which would expose the component
 * instance, not the underlying `<input>`).
 */
function focusSidebarInput(dataTest: string, select = false): void {
    const input = document.querySelector<HTMLInputElement>(
        `[data-test="${dataTest}"]`,
    );

    input?.focus();

    if (select) {
        input?.select();
    }
}

/**
 * Blur the input that received the keydown so its `@blur` handler commits the
 * value (Enter-to-confirm on the inline rename / new-section fields).
 */
function blurInput(event: Event): void {
    if (event.target instanceof HTMLInputElement) {
        event.target.blur();
    }
}

// Creating a new section from the inline form under the "Channels" header.
const sectionFormOpen = ref(false);
const newSectionName = ref('');

async function openSectionForm(): Promise<void> {
    sectionFormOpen.value = true;
    await nextTick();
    focusSidebarInput('create-section-input');
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
                toast.error(
                    t('Failed to create the section. Please try again.'),
                );
            },
        },
    );
}

// Inline renaming of an existing section. The input renders inside the sections
// v-for, so its DOM node is resolved by its section-scoped `data-test` once
// mounted (see focusSidebarInput).
const renamingSectionId = ref<string | null>(null);
const renameValue = ref('');

async function startRename(section: ChannelSection): Promise<void> {
    renamingSectionId.value = section.id;
    renameValue.value = section.name;
    await nextTick();
    focusSidebarInput(`section-rename-input-${section.id}`, true);
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
                toast.error(
                    t('Failed to rename the section. Please try again.'),
                );
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
                toast.error(
                    t('Failed to delete the section. Please try again.'),
                );
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
                    t('Failed to save the sidebar layout. Please try again.'),
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
                    t('Failed to save the sidebar layout. Please try again.'),
                );
            },
        },
    );
}

const { getInitials } = useInitials();
const { switchTeam } = useTeamSwitch();
const { start: startOnboardingTour } = useOnboardingTour();

const quickSwitcherOpen = ref(false);
const { isOpen: shortcutsOpen, toggle: toggleShortcuts } =
    useKeyboardShortcutsModal();

// The viewer's still-pending reminders in this team, feeding the "Reminders"
// list and its sidebar count; the due-and-unacknowledged ones drive the nudges.
const reminders = computed<MessageReminder[]>(() => page.props.reminders ?? []);
const firedReminders = computed<MessageReminder[]>(
    () => page.props.firedReminders ?? [],
);
const remindersDialogOpen = ref(false);

/**
 * Common reload options for a reminder mutation: leave the page in place and
 * refresh only the reminder props so the list and nudges stay current.
 */
const reminderReloadOptions = {
    preserveScroll: true,
    preserveState: true,
    only: ['reminders', 'firedReminders'] as string[],
};

// Jump to the reminded message, then clear its now-acknowledged nudge. The
// clear runs first so the fresh page load no longer carries the fired reminder.
function openReminder(reminder: MessageReminder): void {
    const target = show(
        { team: reminder.teamSlug, channel: reminder.channelSlug },
        { query: { message: reminder.messageId } },
    ).url;

    router.delete(
        destroyReminder({ team: reminder.teamSlug, reminder: reminder.id }).url,
        {
            ...reminderReloadOptions,
            onFinish: () => router.visit(target),
        },
    );
}

// Push a fired reminder out by 20 minutes, re-arming it back to pending.
function snoozeReminder(reminder: MessageReminder): void {
    router.post(
        storeReminder({ team: reminder.teamSlug }).url,
        {
            message_id: reminder.messageId,
            remind_at: new Date(Date.now() + 20 * 60_000).toISOString(),
        },
        {
            ...reminderReloadOptions,
            onSuccess: () =>
                toast.success(t('Reminder snoozed for 20 minutes.')),
            onError: () =>
                toast.error(
                    t('Failed to snooze the reminder. Please try again.'),
                ),
        },
    );
}

// Clear a single reminder — an acknowledged nudge, or a pending row from the list.
function clearReminder(
    reminder: Pick<MessageReminder, 'id' | 'teamSlug'>,
): void {
    router.delete(
        destroyReminder({ team: reminder.teamSlug, reminder: reminder.id }).url,
        reminderReloadOptions,
    );
}

// Clear every pending reminder in the current team at once.
function clearAllReminders(): void {
    if (!currentTeam.value) {
        return;
    }

    router.delete(
        clearRemindersAction({ team: currentTeam.value.slug }).url,
        reminderReloadOptions,
    );
}

// The dialog rows only carry the reminder id; resolve the team from the shared
// prop (all listed reminders belong to the current team).
function clearReminderById(id: string): void {
    const reminder = reminders.value.find((entry) => entry.id === id);

    if (reminder) {
        clearReminder(reminder);
    }
}

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

const { timezone, syncDetectedTimezone } = useTimezone();

onMounted(() => {
    // Lazily pull the (optional) shared invitations so the post-login prompt appears.
    router.reload({ only: ['pendingInvitations'] });

    // Persist the browser's timezone on first login when none is stored yet.
    syncDetectedTimezone();

    // Auto-start the first-run tour for a user who has never completed it, but
    // not while they're deep in settings (the tour anchors live on the channel
    // workspace); replaying is always available from the user menu.
    if (!isSettingsSection.value && shouldAutoStartTour(page.props.auth.user)) {
        startOnboardingTour();
    }
});
</script>

<template>
    <SidebarProvider
        :default-open="page.props.sidebarOpen"
        class="bg-background"
        style="--sidebar-width: calc(272px + 1.75rem)"
    >
        <!-- The first focusable element: a skip link that jumps keyboard users
             past the sidebar straight to the main content. Visually hidden until
             it takes focus. -->
        <a
            href="#main"
            data-test="skip-to-content"
            class="sr-only focus-visible:not-sr-only focus-visible:absolute focus-visible:top-3 focus-visible:left-3 focus-visible:z-50 focus-visible:rounded-md focus-visible:bg-primary focus-visible:px-3 focus-visible:py-2 focus-visible:text-sm focus-visible:font-medium focus-visible:text-primary-foreground focus-visible:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            {{ $t('Skip to content') }}
        </a>

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
                            <Button
                                variant="ghost"
                                data-test="workspace-switcher"
                                class="-m-1 flex h-auto min-w-0 flex-1 items-center justify-start gap-2 rounded-[9px] p-1 text-left transition-colors hover:bg-sidebar-accent"
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
                                            currentTeam?.name ??
                                            $t('Select team')
                                        }}</span
                                    >
                                    <span
                                        class="block text-[11px] text-muted-foreground"
                                        >{{ currentTeam?.membersCount ?? 0 }}
                                        {{
                                            (currentTeam?.membersCount ?? 0) ===
                                            1
                                                ? $t('member')
                                                : $t('members')
                                        }}</span
                                    >
                                </span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" class="w-56">
                            <DropdownMenuLabel
                                class="text-xs text-muted-foreground"
                            >
                                {{ $t('Teams') }}
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
                                    <span class="text-muted-foreground">{{
                                        $t('New team')
                                    }}</span>
                                </DropdownMenuItem>
                            </CreateTeamModal>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <div class="flex shrink-0 items-center gap-1">
                        <Button
                            v-if="canInviteToCurrentTeam"
                            variant="ghost"
                            size="icon"
                            :title="$t('Invite people')"
                            data-test="invite-member-trigger"
                            data-tour="invite"
                            class="size-6 rounded-[7px] border border-sidebar-border text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground"
                            @click="inviteOpen = true"
                        >
                            <UserPlus class="size-3" />
                            <span class="sr-only">{{
                                $t('Invite people')
                            }}</span>
                        </Button>
                        <CreateTeamModal>
                            <Button
                                variant="ghost"
                                size="icon"
                                :title="$t('New team')"
                                data-test="new-team-trigger"
                                class="size-6 rounded-[7px] border border-dashed border-sidebar-border text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground"
                            >
                                <Plus class="size-3" />
                                <span class="sr-only">{{
                                    $t('New team')
                                }}</span>
                            </Button>
                        </CreateTeamModal>
                    </div>
                </div>
            </SidebarHeader>

            <SidebarContent>
                <SettingsNav v-if="isSettingsSection" />
                <nav
                    v-else
                    data-test="channels-nav"
                    :aria-label="$t('Channels')"
                    class="flex min-w-0 flex-col gap-2"
                >
                    <div class="px-2 pt-2">
                        <Button
                            variant="ghost"
                            data-test="quick-switcher-trigger"
                            class="flex h-8 w-full items-center justify-start gap-2 rounded-[9px] bg-muted px-2.5 text-[13px] font-normal text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground"
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
                    <!-- Starred channels, pinned above the main list; the
                             whole section is hidden until the user stars one.
                             Reorderable within itself (its own drag group), but a
                             starred row can't be dragged into a custom section —
                             starring wins, so its move menu stays hidden. -->
                    <SidebarGroup v-if="starredList.length > 0" class="pb-0">
                        <Button
                            variant="ghost"
                            data-test="section-toggle-starred"
                            :aria-expanded="!isSectionCollapsed('starred')"
                            class="flex h-7 w-full items-center justify-start gap-1 rounded-md px-2 text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground uppercase transition-colors hover:bg-sidebar-accent/40 hover:text-sidebar-foreground"
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
                            {{ $t('Starred') }}
                        </Button>
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
                                    class="group/section flex h-7 w-full items-center gap-1 rounded-md pr-1 pl-2 text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground uppercase transition-colors hover:bg-sidebar-accent/40 hover:text-sidebar-foreground"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :data-test="`section-drag-${group.section.id}`"
                                        :aria-label="
                                            $t('Reorder :name', {
                                                name: group.section.name,
                                            })
                                        "
                                        :title="$t('Drag to reorder section')"
                                        class="section-drag-handle size-4 shrink-0 cursor-grab rounded text-muted-foreground/50 opacity-0 transition group-hover/section:opacity-100 hover:bg-transparent hover:text-sidebar-foreground active:cursor-grabbing"
                                    >
                                        <GripVertical class="size-3" />
                                    </Button>
                                    <!-- While renaming, the editor stands in for
                                         the toggle as its sibling — never nested
                                         inside the <button>, which would be
                                         invalid interactive-in-interactive markup
                                         and breaks keyboard focus. -->
                                    <Input
                                        v-if="
                                            renamingSectionId ===
                                            group.section.id
                                        "
                                        v-model="renameValue"
                                        :data-test="`section-rename-input-${group.section.id}`"
                                        class="h-auto min-w-0 flex-1 rounded-sm border-sidebar-border bg-sidebar px-1 py-0.5 text-[11px] tracking-normal text-sidebar-foreground normal-case md:text-[11px] dark:bg-sidebar"
                                        type="text"
                                        maxlength="50"
                                        @keydown.enter.prevent="
                                            blurInput($event)
                                        "
                                        @keydown.esc="cancelRename"
                                        @blur="submitRename(group.section)"
                                    />
                                    <Button
                                        v-else
                                        variant="ghost"
                                        :data-test="`section-toggle-custom-${group.section.id}`"
                                        :aria-expanded="
                                            !group.section.collapsed
                                        "
                                        class="flex h-auto min-w-0 flex-1 items-center justify-start gap-1 rounded-none p-0 text-[10.5px] font-semibold hover:bg-transparent"
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
                                        <span
                                            class="truncate"
                                            @dblclick.stop="
                                                startRename(group.section)
                                            "
                                            >{{ group.section.name }}</span
                                        >
                                    </Button>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                :data-test="`section-menu-${group.section.id}`"
                                                :aria-label="
                                                    $t('Options for :name', {
                                                        name: group.section
                                                            .name,
                                                    })
                                                "
                                                :title="$t('Section options')"
                                                class="size-5 shrink-0 rounded text-muted-foreground/60 opacity-0 transition group-hover/section:opacity-100 hover:bg-transparent hover:text-sidebar-foreground focus-visible:opacity-100 data-[state=open]:opacity-100"
                                            >
                                                <MoreVertical
                                                    class="size-3.5"
                                                />
                                            </Button>
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
                                                {{ $t('Rename') }}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                variant="destructive"
                                                :data-test="`section-delete-${group.section.id}`"
                                                @select="
                                                    deleteSection(group.section)
                                                "
                                            >
                                                <Trash2 class="size-3.5" />
                                                {{ $t('Delete') }}
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
                                        class="px-7 pb-1 text-[12px] text-muted-foreground normal-case"
                                    >
                                        {{ $t('Drag channels here') }}
                                    </p>
                                </SidebarGroupContent>
                            </SidebarGroup>
                        </template>
                    </draggable>

                    <!-- The default "Channels" list: unstarred, unassigned
                             channels. Shares a drag group with the custom sections
                             so channels can be dragged in and out. -->
                    <SidebarGroup>
                        <Button
                            variant="ghost"
                            data-test="section-toggle-channels"
                            :aria-expanded="!isSectionCollapsed('channels')"
                            class="flex h-7 w-full items-center justify-start gap-1 rounded-md px-2 text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground uppercase transition-colors hover:bg-sidebar-accent/40 hover:text-sidebar-foreground"
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
                            {{ $t('Channels') }}
                        </Button>
                        <CreateChannelModal
                            v-if="currentTeam"
                            :team-slug="currentTeam.slug"
                        >
                            <SidebarGroupAction
                                :title="$t('Create channel')"
                                data-test="create-channel-trigger"
                                data-tour="create-channel"
                                class="top-2 size-5 rounded-md text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                            >
                                <Plus class="size-3.25" />
                                <span class="sr-only">{{
                                    $t('Create channel')
                                }}</span>
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
                            <!-- Brand-new workspace: nothing files into the
                                 default list yet. A dashed hint stands in until
                                 the first channel appears. -->
                            <div
                                v-if="defaultList.length === 0"
                                data-test="no-channels-empty"
                                class="mx-1 mt-1.5 flex flex-col gap-1 rounded-[11px] border border-dashed border-sidebar-border px-3 py-3.5 text-center"
                            >
                                <span
                                    class="text-[12.5px] font-semibold text-sidebar-foreground/70"
                                    >{{ $t('No channels yet') }}</span
                                >
                                <span
                                    class="text-[11.5px] leading-[1.45] text-muted-foreground"
                                    >{{
                                        $t(
                                            'Channels keep conversations organized by topic.',
                                        )
                                    }}</span
                                >
                            </div>
                        </SidebarGroupContent>
                    </SidebarGroup>

                    <!-- Direct messages: a fixed group outside the
                             star/section/placement system. Rows render the other
                             participant (self renders "You") with a presence dot
                             and a plain unread badge, ordered by recent activity. -->
                    <SidebarGroup
                        class="pb-0"
                        data-test="direct-messages-group"
                    >
                        <Button
                            variant="ghost"
                            data-test="section-toggle-direct"
                            :aria-expanded="!isSectionCollapsed('direct')"
                            class="flex h-7 w-full items-center justify-start gap-1 rounded-md px-2 text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground uppercase transition-colors hover:bg-sidebar-accent/40 hover:text-sidebar-foreground"
                            @click="toggleSection('direct')"
                        >
                            <ChevronRight
                                class="size-3 shrink-0 transition-transform"
                                :class="
                                    isSectionCollapsed('direct')
                                        ? ''
                                        : 'rotate-90'
                                "
                            />
                            {{ $t('Direct messages') }}
                        </Button>
                        <SidebarGroupAction
                            :title="$t('New message')"
                            data-test="new-dm-trigger"
                            class="top-2 size-5 rounded-md text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                            @click="newDmOpen = true"
                        >
                            <Plus class="size-3.25" />
                            <span class="sr-only">{{ $t('New message') }}</span>
                        </SidebarGroupAction>
                        <SidebarGroupContent
                            v-show="!isSectionCollapsed('direct')"
                            data-test="section-content-direct"
                        >
                            <ul class="flex w-full min-w-0 flex-col gap-1">
                                <DirectMessageListItem
                                    v-for="dm in directList"
                                    :key="dm.id"
                                    :channel="dm"
                                    :team-slug="currentTeam?.slug ?? ''"
                                    :active-channel-slug="activeChannelSlug"
                                    :online="
                                        dm.dmUserId != null &&
                                        onlineIds.has(dm.dmUserId)
                                    "
                                    :is-self="dm.dmUserId === currentUserId"
                                />
                            </ul>
                            <p
                                v-if="directList.length === 0"
                                data-test="direct-messages-empty"
                                class="px-2 pb-1 text-[12px] text-muted-foreground normal-case"
                            >
                                {{ $t('No direct messages yet') }}
                            </p>
                        </SidebarGroupContent>
                    </SidebarGroup>

                    <!-- Create a new custom section. -->
                    <SidebarGroup class="py-0">
                        <SidebarGroupContent>
                            <div v-if="sectionFormOpen" class="px-2">
                                <Input
                                    v-model="newSectionName"
                                    data-test="create-section-input"
                                    class="h-8 w-full rounded-md border-sidebar-border bg-sidebar px-2 py-1 text-[13px] text-sidebar-foreground md:text-[13px] dark:bg-sidebar"
                                    type="text"
                                    maxlength="50"
                                    :placeholder="$t('New section name')"
                                    @keydown.enter.prevent="blurInput($event)"
                                    @keydown.esc="cancelSectionForm"
                                    @blur="createSection"
                                />
                            </div>
                            <Button
                                v-else
                                variant="ghost"
                                data-test="create-section-trigger"
                                class="flex h-7 w-full items-center justify-start gap-1.5 rounded-md px-2 text-[12px] font-normal text-muted-foreground transition-colors hover:bg-sidebar-accent/60 hover:text-sidebar-foreground"
                                @click="openSectionForm"
                            >
                                <FolderPlus class="size-3.5" />
                                {{ $t('New section') }}
                            </Button>
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
                                        class="h-7.5 gap-2 rounded-[9px] px-2.5 text-[13px] text-muted-foreground hover:bg-sidebar-accent/60"
                                    >
                                        <Link
                                            v-if="currentTeam"
                                            :href="
                                                threadsInbox(currentTeam.slug)
                                                    .url
                                            "
                                            data-test="threads-inbox"
                                        >
                                            <MessagesSquare class="size-3.25" />
                                            <span>{{ $t('Threads') }}</span>
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
                                        data-test="reminders-trigger"
                                        class="h-7.5 gap-2 rounded-[9px] px-2.5 text-[13px] text-muted-foreground hover:bg-sidebar-accent/60"
                                        @click="remindersDialogOpen = true"
                                    >
                                        <AlarmClock class="size-3.25" />
                                        <span>{{ $t('Reminders') }}</span>
                                        <span
                                            v-if="reminders.length > 0"
                                            data-test="reminders-badge"
                                            class="ml-auto inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-brass px-1 text-[10px] font-semibold text-brass-foreground"
                                            >{{ reminders.length }}</span
                                        >
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        as-child
                                        class="h-7.5 gap-2 rounded-[9px] px-2.5 text-[13px] text-muted-foreground hover:bg-sidebar-accent/60"
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
                                                class="size-3.25"
                                            />
                                            <span>{{
                                                $t('Search messages')
                                            }}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        as-child
                                        class="h-7.5 gap-2 rounded-[9px] px-2.5 text-[13px] text-muted-foreground hover:bg-sidebar-accent/60"
                                    >
                                        <Link
                                            v-if="currentTeam"
                                            :href="browse(currentTeam.slug).url"
                                            data-test="browse-channels"
                                        >
                                            <Search class="size-3.25" />
                                            <span>{{
                                                $t('Browse channels')
                                            }}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                </nav>
            </SidebarContent>

            <SidebarFooter class="border-t border-sidebar-border p-2.5">
                <NavUser />
            </SidebarFooter>
        </Sidebar>

        <!-- Main card: fills the screen on mobile, floats on the warm canvas
             (matching the dock) from md up. -->
        <SidebarInset
            id="main"
            tabindex="-1"
            class="flex h-svh flex-col overflow-hidden focus-visible:outline-none md:my-3.5 md:mr-3.5 md:h-[calc(100svh-1.75rem)] md:rounded-[14px] md:border md:border-border md:bg-card md:shadow-sm"
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
            :members="teamMembers"
            :current-user-id="currentUserId"
            :team-slug="currentTeam.slug"
            @open-reminders="remindersDialogOpen = true"
        />

        <NewDirectMessageModal
            v-if="currentTeam"
            v-model:open="newDmOpen"
            :members="teamMembers"
            :current-user-id="currentUserId"
            :team-slug="currentTeam.slug"
        />

        <KeyboardShortcutsModal v-model:open="shortcutsOpen" />

        <RemindersDialog
            v-model:open="remindersDialogOpen"
            :reminders="reminders"
            :timezone="timezone"
            @open="openReminder"
            @clear="clearReminderById"
            @clear-all="clearAllReminders"
        />

        <!-- Due reminders slide in, stacked, in the bottom-right corner. The
             wrapper ignores pointer events so it never blocks the app behind
             the gaps; each card re-enables them. -->
        <div
            v-if="firedReminders.length > 0"
            data-test="reminder-nudges"
            class="pointer-events-none fixed right-4 bottom-4 z-50 flex flex-col gap-2.5"
        >
            <ReminderNudge
                v-for="reminder in firedReminders"
                :key="reminder.id"
                :reminder="reminder"
                @open="openReminder"
                @snooze="snoozeReminder"
                @dismiss="clearReminder"
            />
        </div>

        <OnboardingTour />

        <Toaster />
    </SidebarProvider>
</template>
