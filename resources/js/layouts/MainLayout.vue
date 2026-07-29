<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ChevronDown, Plus, X } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { show } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import {
    destroy as destroyReminder,
    store as storeReminder,
} from '@/actions/App/Http/Controllers/Channels/MessageReminderController';
import PasskeyPromptDialog from '@/components/auth/PasskeyPromptDialog.vue';
import DemoBanner from '@/components/DemoBanner.vue';
import DndPauseDialog from '@/components/DndPauseDialog.vue';
import InstallAppCard from '@/components/InstallAppCard.vue';
import InstallAppDialog from '@/components/InstallAppDialog.vue';
import InviteMemberModal from '@/components/InviteMemberModal.vue';
import KeyboardShortcutsModal from '@/components/KeyboardShortcutsModal.vue';
import ChannelsPanel from '@/components/navigation/ChannelsPanel.vue';
import NavigationRail from '@/components/navigation/NavigationRail.vue';
import NavigationTabBar from '@/components/navigation/NavigationTabBar.vue';
import NewMenu from '@/components/navigation/NewMenu.vue';
import RemindersPanel from '@/components/navigation/RemindersPanel.vue';
import SearchPanel from '@/components/navigation/SearchPanel.vue';
import ThreadsPanel from '@/components/navigation/ThreadsPanel.vue';
import UserPanel from '@/components/navigation/UserPanel.vue';
import WorkspaceSheet from '@/components/navigation/WorkspaceSheet.vue';
import NewDirectMessageModal from '@/components/NewDirectMessageModal.vue';
import OnboardingTour from '@/components/OnboardingTour.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import QuickSwitcher from '@/components/QuickSwitcher.vue';
import ReminderNudge from '@/components/ReminderNudge.vue';
import SettingsNav from '@/components/SettingsNav.vue';
import { Button } from '@/components/ui/button';
import { SheetClose } from '@/components/ui/sheet';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarInset,
    SidebarProvider,
} from '@/components/ui/sidebar';
import { Toaster } from '@/components/ui/sonner';
import UpdateIndicator from '@/components/UpdateIndicator.vue';
import UserStatusDialog from '@/components/UserStatusDialog.vue';
import { adjacentSlug } from '@/composables/keyboardShortcuts';
import { useChannelSections } from '@/composables/useChannelSections';
import {
    channelUploadKey,
    channelUploads,
    releaseChannelUploads,
} from '@/composables/useChannelUploads';
import { useChimeNotifications } from '@/composables/useChimeNotifications';
import { useDemoMode } from '@/composables/useDemoMode';
import { useDndPauseDialog } from '@/composables/useDndPauseDialog';
import { useInitials } from '@/composables/useInitials';
import { useInstallDialog } from '@/composables/useInstallDialog';
import { useIsMobile } from '@/composables/useIsMobile';
import { useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts';
import { useKeyboardShortcutsModal } from '@/composables/useKeyboardShortcutsModal';
import { useMessageReminders } from '@/composables/useMessageReminders';
import { useNavPanel } from '@/composables/useNavPanel';
import { useNewDirectMessages } from '@/composables/useNewDirectMessages';
import {
    shouldAutoStartTour,
    useOnboardingTour,
} from '@/composables/useOnboardingTour';
import { useOwnPresence } from '@/composables/useOwnPresence';
import { usePresenceReporter } from '@/composables/usePresenceReporter';
import { useQuickSwitcher } from '@/composables/useQuickSwitcher';
import { useReminderUndo } from '@/composables/useReminderUndo';
import { useSidebarBadges } from '@/composables/useSidebarBadges';
import { useSidebarPosition } from '@/composables/useSidebarPosition';
import { useTeamPresence } from '@/composables/useTeamPresence';
import { useTimezone } from '@/composables/useTimezone';
import { useToast, useToastCount } from '@/composables/useToast';
import { useToastZoneHeight } from '@/composables/useToastZoneHeight';
import { useTranslations } from '@/composables/useTranslations';
import { useUploadProgressToast } from '@/composables/useUploadProgressToast';
import { useUserStatusDialog } from '@/composables/useUserStatusDialog';
import { backgroundVisit } from '@/lib/backgroundVisit';
import type { Channel } from '@/types/channels';
import type { MessageReminder } from '@/types/messages';
import type { RoleOption } from '@/types/teams';

const page = usePage();

const { t } = useTranslations();
const toast = useToast();
const reminderUndo = useReminderUndo();

/**
 * The bottom-right rail is one surface with two zones: transient toasts at the
 * foot, nearest the action that raised them, and persistent reminder nudges
 * above. sonner stacks its toasts absolutely and so leaves nothing to flow
 * around, hence the measurement (#978).
 */
const toastZoneHeight = useToastZoneHeight();

/** How many toasts are up, so F6 only claims focus for a rail that has cards. */
const toastCount = useToastCount();

/**
 * sonner's stock hotkey, which it keeps whenever the rail has no toasts on it.
 * Restoring it there rather than leaving F6 bound is the point: sonner's handler
 * focuses its list unconditionally, and an F6 that pulled the caret out of the
 * composer into an empty corner would be worse than no shortcut at all.
 */
const SONNER_DEFAULT_HOTKEY = ['altKey', 'KeyT'];

/**
 * F6 is sonner's hotkey while it has something to show. Its handler expands the
 * stack — which is what holds every dismiss timer — and focuses the list, and
 * neither is reachable from outside the component, so the shortcut is handed
 * over rather than reimplemented against its internals. The registry entry
 * remains the single source of truth for what F6 does and how it is documented.
 */
const toasterHotkey = computed<string[]>(() =>
    toastCount.value > 0 ? ['F6'] : SONNER_DEFAULT_HOTKEY,
);

/**
 * 16px in from the pane's right edge and 16px above its composer, per the
 * placement in #978. Both insets are published by whatever the pane has at those
 * edges and default to zero, so on a page with neither — settings, auth — the
 * rail falls back to the viewport corner.
 */
const toastOffset = {
    right: 'calc(1rem + var(--rail-right-inset, 0px))',
    bottom: 'calc(1rem + var(--rail-bottom-inset, 0px))',
};

/**
 * Below `md` the toast goes full width minus a 10px gutter and sits 12px above
 * the composer. The composer pads itself by the software keyboard's inset and
 * publishes the result, so the rail lifts with the keyboard rather than hiding
 * behind it.
 */
const toastMobileOffset = {
    left: '10px',
    right: '10px',
    bottom: 'calc(12px + var(--rail-bottom-inset, 0px))',
};

/**
 * The same workspace shell wraps the settings/teams section, but its sidebar
 * swaps the channel list for the settings navigation so there is a single
 * sidebar rather than a nested one.
 */
const isSettingsSection = computed(() => {
    const component = page.component;

    return component.startsWith('settings/') || component.startsWith('teams/');
});

// Chime for qualifying messages across every channel while the workspace is open.
useChimeNotifications();

// The demo banner is a fixed strip above the shell; reserve its height so the
// sidebar and workspace pane sit below it rather than under it.
const { demoMode } = useDemoMode();

// Keep the sidebar unread/mention badges live as messages arrive in channels the
// user is a member of but not currently viewing.
useSidebarBadges();

// Surface a brand-new direct message in the sidebar the moment someone messages
// the viewer for the first time, without a manual reload.
useNewDirectMessages();

// Slide in a nudge the moment a message reminder comes due.
useMessageReminders();

const currentTeam = computed(() => page.props.currentTeam);

// Staged attachments outlive the composer that started them, but not the
// workspace they were staged in: the channels they belong to are gone from the
// sidebar, so their trays have no surface to come back to. Dropping the whole
// registry here is what releases their previews.
watch(
    () => currentTeam.value?.id,
    () => releaseChannelUploads(),
);

const teams = computed(() => page.props.teams ?? []);
const channels = computed(() => page.props.channels ?? []);
const currentUserId = computed(() => String(page.props.auth.user.id));
/** The current team's members, feeding the DM entry points (people picker + ⌘K). */
const teamMembers = computed(() => page.props.teamMembers ?? []);

/** Live presence for the current team, driving the dot on each DM row. */
const { presenceFor, isDndFor } = useTeamPresence(() => currentTeam.value?.id);

// Report this tab's own idle state from the layout every authenticated surface
// mounts, so someone reading a settings page still counts as here.
usePresenceReporter();

/** The "New message" people picker opened from the "Direct messages" header. */
const newDmOpen = ref(false);
const activeChannelSlug = computed(
    () => (page.props.channel as { slug?: string } | undefined)?.slug ?? null,
);
const pendingInvitations = computed(() => page.props.pendingInvitations ?? []);
const hasUnreadThreads = computed(() => page.props.hasUnreadThreads ?? false);

/**
 * The sidebar's channels by the key their upload tray is filed under, so the
 * upload toast can name and open a channel without taking the key apart again.
 */
const channelsByUploadKey = computed(() => {
    const team = currentTeam.value;

    if (!team) {
        return new Map<string, Channel>();
    }

    return new Map(
        channels.value.map((channel) => [
            channelUploadKey(team.slug, channel.slug),
            channel,
        ]),
    );
});

/**
 * Report staged uploads whose composer is no longer on screen. It lives here
 * because the layout is what knows which channel that is — and it outlives
 * every channel the user passes through, which is the whole point of it.
 */
useUploadProgressToast({
    channels: () => channelUploads.value,
    activeChannelKey: () =>
        currentTeam.value && activeChannelSlug.value
            ? channelUploadKey(currentTeam.value.slug, activeChannelSlug.value)
            : null,
    channelName: (key) => {
        const channel = channelsByUploadKey.value.get(key);

        if (!channel) {
            return null;
        }

        return channel.isDirect ? channel.name : `#${channel.name}`;
    },
    openChannel: (key) => {
        const channel = channelsByUploadKey.value.get(key);

        if (channel && currentTeam.value) {
            router.visit(
                show({ team: currentTeam.value.slug, channel: channel.slug })
                    .url,
            );
        }
    },
});

/**
 * Whether the reminders glyph wears its dot. The pending rows already ride
 * along on every workspace request, so the cue costs nothing beyond reading
 * them, and every reminder mutation reloads that prop — which is what clears
 * the dot the moment the last row is checked off (#963).
 */
const hasPendingReminders = computed(
    () => (page.props.reminders ?? []).length > 0,
);

/**
 * The workspace sheet's "invite people" row reuses the member-invite modal; the
 * permission and assignable roles ride along on the shared workspace props.
 */
const canInviteToCurrentTeam = computed(
    () => page.props.canInviteToCurrentTeam ?? false,
);
const invitableRoles = computed<RoleOption[]>(
    () => page.props.invitableRoles ?? [],
);
const inviteOpen = ref(false);

/**
 * Whether the pending-invitations modal is presented. It opens itself the moment
 * the (lazily shared) invitations land, and the workspace sheet's "Join a
 * workspace" row reopens it once the viewer has dismissed it.
 */
const invitationsOpen = ref(true);

const { getInitials } = useInitials();
const { start: startOnboardingTour } = useOnboardingTour();

/**
 * The "+ New" menu lives in the dock header, but the field it opens belongs at
 * the foot of the conversation list — so opening it is all the shell keeps of
 * section CRUD; {@see ChannelsPanel} renders and commits the form.
 */
const { openSectionForm } = useChannelSections();

const { isOpen: quickSwitcherOpen, toggle: toggleQuickSwitcher } =
    useQuickSwitcher();
const { isOpen: shortcutsOpen, toggle: toggleShortcuts } =
    useKeyboardShortcutsModal();
const { isOpen: statusDialogOpen } = useUserStatusDialog();
const { isOpen: dndPauseDialogOpen } = useDndPauseDialog();
const { isOpen: installDialogOpen } = useInstallDialog();

/**
 * The reminders that have come due and await acknowledgement, driving the
 * nudges. The still-pending ones belong to the Reminders destination, which
 * reads them from the same shared props itself.
 */
const firedReminders = computed<MessageReminder[]>(
    () => page.props.firedReminders ?? [],
);

/**
 * Common reload options for a reminder mutation: leave the page in place and
 * refresh only the reminder props so the list and nudges stay current.
 */
const reminderReloadOptions = {
    preserveScroll: true,
    preserveState: true,
    only: ['reminders', 'firedReminders'] as string[],
};

/**
 * Jump to the reminded message, then clear its now-acknowledged nudge. The
 * clear runs first so the fresh page load no longer carries the fired reminder.
 */
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

/** Push a fired reminder out by 20 minutes, re-arming it back to pending. */
function snoozeReminder(reminder: MessageReminder): void {
    // A snooze is a re-arm of a row the user already had, so Undo puts the
    // original time back rather than clearing the reminder outright.
    const previous = reminderUndo.snapshot(reminder.messageId);

    router.post(
        storeReminder({ team: reminder.teamSlug }).url,
        {
            message_id: reminder.messageId,
            remind_at: new Date(Date.now() + 20 * 60_000).toISOString(),
        },
        {
            ...reminderReloadOptions,
            onSuccess: () =>
                toast.success(t('Reminder snoozed'), {
                    detail: t('For 20 minutes'),
                    // Shares the set-reminder key: a snooze and a set on the
                    // same message must not leave two Undos on screen, only one
                    // of which still reverses anything.
                    key: `reminder:${reminder.messageId}`,
                    action: {
                        label: t('Undo'),
                        run: () =>
                            reminderUndo.undo(
                                reminder.teamSlug,
                                reminder.messageId,
                                previous,
                            ),
                    },
                }),
            onError: () => toast.error(t('Failed to snooze the reminder')),
        },
    );
}

/** Clear a single reminder — an acknowledged nudge, or a pending row from the list. */
function clearReminder(
    reminder: Pick<MessageReminder, 'id' | 'teamSlug'>,
): void {
    router.delete(
        destroyReminder({ team: reminder.teamSlug, reminder: reminder.id }).url,
        reminderReloadOptions,
    );
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

/**
 * Move focus to the bottom-right rail, so a card's action is reachable before
 * its timer runs out. The rail is one surface with two zones, and F6 goes to
 * whichever cards are on it — but only one of the zones is ours.
 *
 * The toast zone belongs to sonner, and {@link toasterHotkey} hands F6 to it:
 * its own handler expands the stack, which is what *pauses* the dismiss timers,
 * and none of that is reachable from out here (focusing the list is not enough —
 * sonner counts only a pointer press as interaction). So this handler owns the
 * zone sonner knows nothing about, and takes precedence when both are occupied:
 * the nudges are the upper zone and the persistent cards, and sonner's handler
 * has already run by the time this one does.
 */
function focusNotificationRail(): void {
    document
        .querySelector<HTMLElement>('[data-test="reminder-nudges"]')
        ?.focus();
}

useKeyboardShortcuts({
    'quick-switcher': () => toggleQuickSwitcher(),
    'previous-channel': () => moveChannel(-1),
    'next-channel': () => moveChannel(1),
    'focus-notifications': () => focusNotificationRail(),
    'show-shortcuts': () => toggleShortcuts(),
});

const { syncDetectedTimezone } = useTimezone();

/**
 * Which edge the dock sits on, read from the shared user prop so the redirect
 * after a change re-binds :side live (no reload).
 */
const { sidebarPosition } = useSidebarPosition();

/**
 * Whether the dock currently renders as the full-screen mobile Sheet, which is
 * when its header carries the close affordance (#834): full screen leaves no
 * visible scrim to tap, so the header X is the visible way out.
 */
const isMobileViewport = useIsMobile();

/**
 * Which pinned destination the dock's panel is rendering. The rail (desktop)
 * and the tab bar (mobile drawer) both drive this one seam, so the panel swaps
 * while they stay put; `?nav=` on the current shell route keeps it shareable
 * and reload-proof. The conversation list is the default destination.
 */
const { activeDestination, openDestination } = useNavPanel();

/** The viewer's own presence, drawn on the rail's and the tab bar's avatar. */
const { presence: ownPresence, isDnd: ownDnd } = useOwnPresence();

/**
 * The one-time security prompt owed to an account registered in this session.
 * While one is pending the prompt owns the first paint and the tour waits behind
 * it; the prompt hands over once it is answered or found unshowable.
 */
const postRegistrationPrompt = computed(
    () => page.props.postRegistrationPrompt ?? null,
);

/**
 * Auto-start the first-run tour for a user who has never completed it, but not
 * while they're deep in settings (the tour anchors live on the channel
 * workspace); replaying is always available from the user menu.
 */
function maybeStartOnboardingTour(promptPending: boolean): void {
    if (
        !isSettingsSection.value &&
        shouldAutoStartTour(page.props.auth.user, promptPending)
    ) {
        startOnboardingTour();
    }
}

onMounted(() => {
    // Lazily pull the (optional) shared invitations so the post-login prompt
    // appears. It lands moments after the first render, when the user may already
    // be navigating, so it stays off the synchronous queue ({@see backgroundVisit}).
    router.reload({ ...backgroundVisit, only: ['pendingInvitations'] });

    // Persist the browser's timezone on first login when none is stored yet.
    syncDetectedTimezone();

    maybeStartOnboardingTour(postRegistrationPrompt.value !== null);
});
</script>

<template>
    <!-- The dock is a constant 356px card — a 56px destination rail plus the
         300px panel — so opening a destination swaps the panel's contents
         without the shell jumping. The extra 1.75rem is the floating card's own
         gutter (`p-3.5` on the Sidebar below), not part of the drawn width. -->
    <SidebarProvider
        :default-open="page.props.sidebarOpen"
        :class="['bg-background', { 'pt-(--demo-banner-height)': demoMode }]"
        style="--sidebar-width: calc(356px + 1.75rem)"
    >
        <DemoBanner />

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

        <!-- The dock: a single floating card, split into the destination rail
             and the panel it drives. On mobile the whole card slides in as the
             built-in Sheet, where the tab bar stands in for the rail. -->
        <Sidebar
            :side="sidebarPosition"
            collapsible="offcanvas"
            variant="floating"
            :class="[
                'p-3.5',
                {
                    'md:top-(--demo-banner-height) md:h-[calc(100dvh-var(--demo-banner-height))]':
                        demoMode,
                },
            ]"
        >
            <!-- Docked right, the rail mirrors to the outer edge so it never
                 ends up wedged between the panel and the workspace card. -->
            <div
                class="flex min-h-0 w-full flex-1"
                :class="
                    sidebarPosition === 'right'
                        ? 'flex-row-reverse'
                        : 'flex-row'
                "
            >
                <NavigationRail
                    class="hidden md:flex"
                    :class="
                        sidebarPosition === 'right' ? 'border-l' : 'border-r'
                    "
                    :active="activeDestination"
                    :user="page.props.auth.user"
                    :teams="teams"
                    :presence="ownPresence"
                    :is-dnd="ownDnd"
                    :has-unread-threads="hasUnreadThreads"
                    :has-pending-reminders="hasPendingReminders"
                    @select="openDestination"
                    @invite="inviteOpen = true"
                    @join="invitationsOpen = true"
                />

                <div class="flex min-h-0 min-w-0 flex-1 flex-col">
                    <SidebarHeader
                        class="gap-0 border-b border-sidebar-border p-3.5 pb-2.5"
                    >
                        <div class="flex items-center gap-2">
                            <!-- The whole identity zone is the workspace sheet's
                                 trigger. Desktop drops the workspace avatar —
                                 the rail already carries it — while the drawer,
                                 which has no rail, keeps it. -->
                            <WorkspaceSheet
                                @invite="inviteOpen = true"
                                @join="invitationsOpen = true"
                            >
                                <Button
                                    variant="ghost"
                                    data-test="workspace-switcher"
                                    data-tour="invite"
                                    class="-m-1 flex h-auto min-w-0 flex-1 items-center justify-start gap-2.5 rounded-[9px] p-1 text-left transition-colors hover:bg-sidebar-accent"
                                >
                                    <span
                                        v-if="isMobileViewport"
                                        aria-hidden="true"
                                        class="flex size-10 shrink-0 items-center justify-center rounded-[11px] bg-sidebar-primary text-[13px] font-semibold text-sidebar-primary-foreground"
                                        >{{
                                            getInitials(currentTeam?.name ?? '')
                                        }}</span
                                    >
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="flex items-center gap-1.25"
                                        >
                                            <span
                                                class="truncate text-sm font-semibold text-sidebar-foreground max-md:text-base"
                                                >{{
                                                    currentTeam?.name ??
                                                    $t('Select team')
                                                }}</span
                                            >
                                            <ChevronDown
                                                class="size-3 shrink-0 text-muted-foreground"
                                            />
                                        </span>
                                        <span
                                            class="block text-[11px] text-muted-foreground max-md:text-[12.5px]"
                                            >{{
                                                (currentTeam?.membersCount ??
                                                    0) === 1
                                                    ? $t(':count member', {
                                                          count: 1,
                                                      })
                                                    : $t(':count members', {
                                                          count:
                                                              currentTeam?.membersCount ??
                                                              0,
                                                      })
                                            }}</span
                                        >
                                    </span>
                                </Button>
                            </WorkspaceSheet>
                            <div class="flex shrink-0 items-center gap-1">
                                <NewMenu
                                    v-if="currentTeam"
                                    :team-slug="currentTeam.slug"
                                    @message="newDmOpen = true"
                                    @section="openSectionForm"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :title="$t('New')"
                                        data-test="new-menu-trigger"
                                        class="size-7 rounded-[9px] bg-sidebar-accent text-sidebar-foreground transition-colors hover:bg-sidebar-accent/70 data-[state=open]:bg-brass data-[state=open]:text-brass-foreground max-md:size-11 max-md:rounded-[12px]"
                                    >
                                        <Plus
                                            class="size-3.75 max-md:size-5.25"
                                        />
                                        <span class="sr-only">{{
                                            $t('New')
                                        }}</span>
                                    </Button>
                                </NewMenu>
                                <SheetClose v-if="isMobileViewport" as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :title="$t('Close')"
                                        data-test="dock-close"
                                        class="size-11 rounded-[12px] text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground"
                                    >
                                        <X class="size-5" />
                                        <span class="sr-only">{{
                                            $t('Close')
                                        }}</span>
                                    </Button>
                                </SheetClose>
                            </div>
                        </div>
                    </SidebarHeader>

                    <SidebarContent>
                        <SettingsNav v-if="isSettingsSection" />
                        <ChannelsPanel
                            v-else-if="activeDestination === 'channels'"
                            :presence-for="presenceFor"
                            :is-dnd-for="isDndFor"
                            @new-message="newDmOpen = true"
                        />
                        <ThreadsPanel
                            v-else-if="activeDestination === 'threads'"
                        />
                        <RemindersPanel
                            v-else-if="activeDestination === 'reminders'"
                        />
                        <SearchPanel
                            v-else-if="activeDestination === 'search'"
                        />
                        <!-- Every destination now has a panel of its own, so
                             there is no generic frame left to fall back to. -->
                        <UserPanel
                            v-else-if="activeDestination === 'you'"
                            @invite="inviteOpen = true"
                            @join="invitationsOpen = true"
                        />
                    </SidebarContent>

                    <!-- The install card and the update indicator belong to the
                         conversation list, not to every destination: they are
                         workspace furniture, and the other panels own their own
                         footers. The footer carries nothing else now — the user
                         chip it used to hold became the "You" destination. -->
                    <SidebarFooter
                        v-if="activeDestination === 'channels'"
                        class="border-t border-sidebar-border p-2.5"
                    >
                        <InstallAppCard />
                        <UpdateIndicator />
                    </SidebarFooter>

                    <NavigationTabBar
                        class="md:hidden"
                        :active="activeDestination"
                        :user="page.props.auth.user"
                        :presence="ownPresence"
                        :is-dnd="ownDnd"
                        :has-unread-threads="hasUnreadThreads"
                        :has-pending-reminders="hasPendingReminders"
                        @select="openDestination"
                    />
                </div>
            </div>
        </Sidebar>

        <!-- Main card: below the breakpoint the card *is* the screen — one pane
             on an 8px canvas gutter, no rail beside it. From md up it floats on
             the warm canvas (matching the dock). -->
        <!-- Mirror the floating gap onto the correct edge: with the dock on the
             right, the inset reorders ahead of it and takes its outer margin on
             the left instead of the right. -->
        <SidebarInset
            id="main"
            tabindex="-1"
            class="mx-2 my-2 flex flex-col overflow-hidden rounded-[14px] border border-border bg-card shadow-sm focus-visible:outline-none md:my-3.5"
            :class="[
                sidebarPosition === 'right'
                    ? 'md:order-first md:mr-0 md:ml-3.5'
                    : 'md:mr-3.5 md:ml-0',
                demoMode
                    ? 'h-[calc(100dvh-1rem-var(--demo-banner-height))] md:h-[calc(100dvh-1.75rem-var(--demo-banner-height))]'
                    : 'h-[calc(100dvh-1rem)] md:h-[calc(100dvh-1.75rem)]',
            ]"
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
            v-model:open="invitationsOpen"
            :invitations="pendingInvitations"
        />

        <QuickSwitcher
            v-if="currentTeam"
            v-model:open="quickSwitcherOpen"
            :channels="channels"
            :members="teamMembers"
            :current-user-id="currentUserId"
            :team-slug="currentTeam.slug"
            :presence-for="presenceFor"
            :is-dnd-for="isDndFor"
            @open-reminders="openDestination('reminders')"
        />

        <NewDirectMessageModal
            v-if="currentTeam"
            v-model:open="newDmOpen"
            :members="teamMembers"
            :current-user-id="currentUserId"
            :team-slug="currentTeam.slug"
        />

        <KeyboardShortcutsModal v-model:open="shortcutsOpen" />

        <UserStatusDialog v-model:open="statusDialogOpen" />

        <InstallAppDialog v-model:open="installDialogOpen" />

        <DndPauseDialog v-model:open="dndPauseDialogOpen" />

        <!-- The rail's upper zone. Due reminders are persistent, not transient,
             so they sit *above* the toasts: the transient thing belongs nearest
             the action that caused it. The wrapper ignores pointer events so it
             never blocks the app behind the gaps; each card re-enables them.

             Both zones share the rail's right inset, which the conversation pane
             publishes while a side panel claims that edge — so a nudge no longer
             straddles the thread panel either. -->
        <div
            v-if="firedReminders.length > 0"
            data-test="reminder-nudges"
            role="region"
            :aria-label="$t('Reminders')"
            tabindex="-1"
            class="pointer-events-none fixed z-50 flex flex-col gap-2.5"
            :style="{
                right: 'calc(1rem + var(--rail-right-inset, 0px))',
                bottom: `calc(1rem + var(--rail-bottom-inset, 0px) + ${toastZoneHeight}px)`,
            }"
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

        <!-- The post-registration security prompt goes ahead of the tour, and
             starts it on its way out. Mounted only while one is queued, so an
             ordinary sign-in reaches the tour exactly as it always did. -->
        <PasskeyPromptDialog
            v-if="postRegistrationPrompt === 'passkey'"
            @done="maybeStartOnboardingTour(false)"
        />

        <OnboardingTour />

        <Toaster
            :offset="toastOffset"
            :mobile-offset="toastMobileOffset"
            :hotkey="toasterHotkey"
        />
    </SidebarProvider>
</template>
