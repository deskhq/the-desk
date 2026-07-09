<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { MessageSquareText, MessagesSquare, Plus, Search } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import {
    browse,
    show,
} from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { index as searchMessages } from '@/actions/App/Http/Controllers/Channels/SearchController';
import { index as threadsInbox } from '@/actions/App/Http/Controllers/Channels/ThreadsController';
import CreateChannelModal from '@/components/CreateChannelModal.vue';
import CreateTeamModal from '@/components/CreateTeamModal.vue';
import NavUser from '@/components/NavUser.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import QuickSwitcher from '@/components/QuickSwitcher.vue';
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import { Separator } from '@/components/ui/separator';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
} from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useChimeNotifications } from '@/composables/useChimeNotifications';
import { useInitials } from '@/composables/useInitials';
import { useSidebarBadges } from '@/composables/useSidebarBadges';
import { useTeamSwitch } from '@/composables/useTeamSwitch';

const page = usePage();

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

const { getInitials } = useInitials();
const { switchTeam } = useTeamSwitch();

// Cmd/Ctrl+K toggles the quick switcher from anywhere in the workspace.
const quickSwitcherOpen = ref(false);

function handleQuickSwitcherShortcut(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        quickSwitcherOpen.value = !quickSwitcherOpen.value;
    }
}

onMounted(() => {
    // Lazily pull the (optional) shared invitations so the post-login prompt appears.
    router.reload({ only: ['pendingInvitations'] });
    window.addEventListener('keydown', handleQuickSwitcherShortcut);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleQuickSwitcherShortcut);
});
</script>

<template>
    <SidebarProvider style="--sidebar-width: calc(3.5rem + 16rem)">
        <Sidebar collapsible="offcanvas" class="overflow-hidden">
            <!-- Nested rail + channel list; a single flex-row wrapper keeps them
                 side by side in both the desktop sidebar and the mobile Sheet. -->
            <div class="flex h-full w-full flex-row">
                <!-- Team rail -->
                <div
                    class="flex w-14 shrink-0 flex-col items-center gap-2 border-r border-sidebar-border bg-sidebar-rail py-3"
                    data-test="team-rail"
                >
                    <div
                        v-for="team in teams"
                        :key="team.id"
                        class="relative flex w-full justify-center"
                    >
                        <span
                            v-if="team.id === currentTeam?.id"
                            aria-hidden="true"
                            class="absolute top-1/2 left-0 h-[22px] w-[3px] -translate-y-1/2 rounded-r-full bg-primary"
                        />
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    data-test="team-rail-item"
                                    :data-active="team.id === currentTeam?.id"
                                    :aria-current="
                                        team.id === currentTeam?.id
                                            ? 'true'
                                            : undefined
                                    "
                                    class="flex size-9 items-center justify-center rounded-[10px] text-xs font-semibold transition-colors"
                                    :class="
                                        team.id === currentTeam?.id
                                            ? 'bg-primary text-primary-foreground'
                                            : 'border border-border text-muted-foreground hover:bg-sidebar-accent'
                                    "
                                    @click="switchTeam(team)"
                                >
                                    {{ getInitials(team.name) }}
                                </button>
                            </TooltipTrigger>
                            <TooltipContent side="right">
                                {{ team.name }}
                            </TooltipContent>
                        </Tooltip>
                    </div>

                    <Separator class="my-1 w-6" />

                    <CreateTeamModal>
                        <button
                            type="button"
                            title="New team"
                            data-test="team-rail-add"
                            class="flex size-9 items-center justify-center rounded-[10px] border border-dashed border-border text-muted-foreground transition-colors hover:bg-sidebar-accent"
                        >
                            <Plus class="size-[15px]" />
                            <span class="sr-only">New team</span>
                        </button>
                    </CreateTeamModal>
                </div>

                <!-- Channel sidebar -->
                <Sidebar collapsible="none" class="flex-1">
                    <SidebarHeader
                        class="h-12 shrink-0 flex-row items-center justify-between border-b border-sidebar-border px-3.5"
                    >
                        <TeamSwitcher />
                    </SidebarHeader>

                    <SidebarContent>
                        <div class="px-2 pt-2">
                            <button
                                type="button"
                                data-test="quick-switcher-trigger"
                                class="flex w-full items-center gap-2 rounded-md border border-sidebar-border bg-sidebar-accent/40 px-2.5 py-1.5 text-[13px] text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground"
                                @click="quickSwitcherOpen = true"
                            >
                                <Search class="size-[15px] shrink-0" />
                                <span>Jump to…</span>
                                <kbd
                                    class="ml-auto rounded border border-sidebar-border bg-sidebar px-1.5 py-0.5 font-mono text-[10px] tracking-wide text-muted-foreground"
                                    >⌘K</kbd
                                >
                            </button>
                        </div>
                        <SidebarGroup>
                            <SidebarGroupLabel
                                class="h-7 px-2 text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                            >
                                Channels
                            </SidebarGroupLabel>
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
                            <SidebarGroupContent>
                                <SidebarMenu>
                                    <SidebarMenuItem
                                        v-for="channel in channels"
                                        :key="channel.id"
                                    >
                                        <SidebarMenuButton
                                            as-child
                                            :is-active="
                                                channel.slug ===
                                                activeChannelSlug
                                            "
                                            :data-muted="channel.muted"
                                            class="h-[30px] gap-1.5 rounded-md px-2 text-[13.5px] text-sidebar-foreground/80 hover:bg-sidebar-accent/60 hover:text-sidebar-foreground data-[active=true]:relative data-[active=true]:bg-sidebar-accent data-[active=true]:pl-3.5 data-[active=true]:font-medium data-[active=true]:text-sidebar-accent-foreground data-[muted=true]:opacity-55 data-[muted=true]:hover:opacity-100"
                                        >
                                            <Link
                                                v-if="currentTeam"
                                                :href="
                                                    show({
                                                        team: currentTeam.slug,
                                                        channel: channel.slug,
                                                    }).url
                                                "
                                            >
                                                <span
                                                    v-if="
                                                        channel.slug ===
                                                        activeChannelSlug
                                                    "
                                                    aria-hidden="true"
                                                    class="absolute top-[7px] bottom-[7px] left-0 w-[3px] rounded-full bg-primary"
                                                />
                                                <span
                                                    class="font-medium"
                                                    :class="
                                                        channel.slug ===
                                                        activeChannelSlug
                                                            ? 'text-sidebar-foreground/70'
                                                            : 'text-muted-foreground/80'
                                                    "
                                                    >#</span
                                                >
                                                <span
                                                    class="truncate"
                                                    :class="
                                                        channel.unreadCount > 0
                                                            ? 'font-semibold text-sidebar-foreground'
                                                            : ''
                                                    "
                                                    >{{ channel.name }}</span
                                                >
                                                <!-- A numeric badge for unread @mentions takes priority; -->
                                                <!-- otherwise a plain dot marks the channel as unread. -->
                                                <span
                                                    v-if="
                                                        channel.mentionCount > 0
                                                    "
                                                    data-test="mention-badge"
                                                    class="ml-auto flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-primary px-1 text-[11px] font-semibold text-primary-foreground tabular-nums"
                                                    :aria-label="`${channel.mentionCount} unread mentions`"
                                                    >{{
                                                        channel.mentionCount
                                                    }}</span
                                                >
                                                <span
                                                    v-else-if="
                                                        channel.unreadCount > 0
                                                    "
                                                    data-test="unread-dot"
                                                    aria-hidden="true"
                                                    class="ml-auto size-1.5 rounded-full bg-primary"
                                                />
                                            </Link>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                    <SidebarMenuItem>
                                        <SidebarMenuButton
                                            as-child
                                            class="mt-1.5 h-[30px] gap-1.5 rounded-md px-2 text-[13px] text-muted-foreground hover:bg-sidebar-accent/60"
                                        >
                                            <Link
                                                v-if="currentTeam"
                                                :href="
                                                    threadsInbox(
                                                        currentTeam.slug,
                                                    ).url
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
                                                    class="ml-auto size-1.5 rounded-full bg-primary"
                                                />
                                            </Link>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                    <SidebarMenuItem>
                                        <SidebarMenuButton
                                            as-child
                                            class="h-[30px] gap-1.5 rounded-md px-2 text-[13px] text-muted-foreground hover:bg-sidebar-accent/60"
                                        >
                                            <Link
                                                v-if="currentTeam"
                                                :href="
                                                    searchMessages(
                                                        currentTeam.slug,
                                                    ).url
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
                                            class="h-[30px] gap-1.5 rounded-md px-2 text-[13px] text-muted-foreground hover:bg-sidebar-accent/60"
                                        >
                                            <Link
                                                v-if="currentTeam"
                                                :href="
                                                    browse(currentTeam.slug).url
                                                "
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
                    </SidebarContent>

                    <SidebarFooter class="border-t border-sidebar-border p-2">
                        <NavUser />
                    </SidebarFooter>
                </Sidebar>
            </div>
        </Sidebar>

        <SidebarInset class="flex h-svh flex-col">
            <slot />
        </SidebarInset>

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
    </SidebarProvider>
</template>
