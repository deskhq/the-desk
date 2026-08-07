<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ChevronDown, Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import DemoBanner from '@/components/DemoBanner.vue';
import DialogHost from '@/components/DialogHost.vue';
import InstallAppCard from '@/components/InstallAppCard.vue';
import ChannelsPanel from '@/components/navigation/ChannelsPanel.vue';
import NavigationRail from '@/components/navigation/NavigationRail.vue';
import NavigationTabBar from '@/components/navigation/NavigationTabBar.vue';
import NewMenu from '@/components/navigation/NewMenu.vue';
import RemindersPanel from '@/components/navigation/RemindersPanel.vue';
import SearchPanel from '@/components/navigation/SearchPanel.vue';
import ThreadsPanel from '@/components/navigation/ThreadsPanel.vue';
import UserPanel from '@/components/navigation/UserPanel.vue';
import WorkspaceSheet from '@/components/navigation/WorkspaceSheet.vue';
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
import { useAdjacentChannelPrefetch } from '@/composables/useAdjacentChannelPrefetch';
import { useChannelSections } from '@/composables/useChannelSections';
import { useChannelUploadToasts } from '@/composables/useChannelUploadToasts';
import { useChimeNotifications } from '@/composables/useChimeNotifications';
import { useDemoMode } from '@/composables/useDemoMode';
import { useInitials } from '@/composables/useInitials';
import { useIsMobile } from '@/composables/useIsMobile';
import { useMessageReminders } from '@/composables/useMessageReminders';
import { useNavPanel } from '@/composables/useNavPanel';
import { useNewDirectMessages } from '@/composables/useNewDirectMessages';
import { useOwnPresence } from '@/composables/useOwnPresence';
import { usePresenceReporter } from '@/composables/usePresenceReporter';
import { useReminders } from '@/composables/useReminders';
import {
    TOAST_MOBILE_OFFSET,
    TOAST_OFFSET,
    useShellFocus,
} from '@/composables/useShellFocus';
import { useShellShortcuts } from '@/composables/useShellShortcuts';
import { useShellStartup } from '@/composables/useShellStartup';
import { useSidebarBadges } from '@/composables/useSidebarBadges';
import { useSidebarPosition } from '@/composables/useSidebarPosition';
import { useTeamPresenceSubscription } from '@/composables/useTeamPresence';
import { useToastZoneHeight } from '@/composables/useToastZoneHeight';
import { useUnreadDigest } from '@/composables/useUnreadDigest';
import type { MessageReminder } from '@/types/messages';

const page = usePage();

/**
 * The bottom-right rail is one surface with two zones: transient toasts at the
 * foot, nearest the action that raised them, and persistent reminder nudges
 * above. sonner stacks its toasts absolutely and so leaves nothing to flow
 * around, hence the measurement (#978).
 */
const toastZoneHeight = useToastZoneHeight();

/** Which of the rail's two zones F6 claims, and when. */
const { toasterHotkey } = useShellFocus();

// Chime for qualifying messages across every channel while the workspace is open.
useChimeNotifications();

// The demo banner is a fixed strip above the shell; reserve its height so the
// sidebar and workspace pane sit below it rather than under it.
const { demoMode } = useDemoMode();

// Keep the sidebar unread/mention badges live as messages arrive in channels the
// user is a member of but not currently viewing.
useSidebarBadges();

// Fetch the two channels the ⌘↑ / ⌘↓ walk can reach from the open one. The
// same fleet subscribed above is what flushes those entries when a message
// lands in one of them.
useAdjacentChannelPrefetch();

// Surface a brand-new direct message in the sidebar the moment someone messages
// the viewer for the first time, without a manual reload.
useNewDirectMessages();

// Slide in a nudge the moment a message reminder comes due.
useMessageReminders();

// Report staged uploads whose composer is no longer on screen — the shell is
// what outlives every channel the user passes through.
useChannelUploadToasts();

// The app-wide shortcuts: the palette, the help modal, the channel walk, and the
// handover of F6 to the notification rail.
useShellShortcuts();

const currentTeam = computed(() => page.props.currentTeam);
const teams = computed(() => page.props.teams ?? []);

// The shell holds the team's presence channel open for as long as it is
// mounted; every dot surface reads the roster through `useTeamPresence()`
// rather than being handed it.
useTeamPresenceSubscription(() => currentTeam.value?.id);

// Report this tab's own idle state from the layout every authenticated surface
// mounts, so someone reading a settings page still counts as here.
usePresenceReporter();

// The Threads dot, read off the shared unread digest along with every other
// badge in the shell rather than from a flag of its own — the two were three
// readings of the same fact before the digest consolidated them.
const digest = useUnreadDigest();
const hasUnreadThreads = computed(() => digest.value.threads);

/**
 * Whether the reminders glyph wears its dot. The pending rows already ride
 * along on every workspace request, so the cue costs nothing beyond reading
 * them, and every reminder mutation reloads that prop — which is what clears
 * the dot the moment the last row is checked off (#963).
 */
const hasPendingReminders = computed(
    () => (page.props.reminders ?? []).length > 0,
);

const { getInitials } = useInitials();

/**
 * The "+ New" menu lives in the dock header, but the field it opens belongs at
 * the foot of the conversation list — so opening it is all the shell keeps of
 * section CRUD; {@see ChannelsPanel} renders and commits the form.
 */
const { openSectionForm } = useChannelSections();

/**
 * The reminders that have come due and await acknowledgement, driving the
 * nudges. The still-pending ones belong to the Reminders destination, which
 * reads them from the same shared props itself.
 */
const firedReminders = computed<MessageReminder[]>(
    () => page.props.firedReminders ?? [],
);

/** What a nudge's three actions do; the writes themselves live in the facade. */
const {
    open: openReminder,
    snooze: snoozeReminder,
    clear: clearReminder,
} = useReminders();

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
 * What the shell does on its first paint, and the settings-section answer the
 * sidebar swap shares with the tour's gate: the same workspace shell wraps the
 * settings/teams section, but its sidebar swaps the channel list for the
 * settings navigation so there is a single sidebar rather than a nested one.
 */
const { isSettingsSection, startTourIfEligible } = useShellStartup();
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
                            <WorkspaceSheet>
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
                        <UserPanel v-else-if="activeDestination === 'you'" />
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

        <DialogHost @prompt-answered="startTourIfEligible(false)" />

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

        <Toaster
            :offset="TOAST_OFFSET"
            :mobile-offset="TOAST_MOBILE_OFFSET"
            :hotkey="toasterHotkey"
        />
    </SidebarProvider>
</template>
