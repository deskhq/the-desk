<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Check, LogIn, Plus, Settings, UserPlus, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CreateTeamModal from '@/components/CreateTeamModal.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/composables/useInitials';
import { useIsMobile } from '@/composables/useIsMobile';
import { useTeamSwitch } from '@/composables/useTeamSwitch';
import { useTranslations } from '@/composables/useTranslations';
import { edit as teamEdit } from '@/routes/teams';
import type { Team } from '@/types';

/**
 * The workspace sheet: one surface behind three anchors — the desktop panel
 * header, the mobile drawer header, and the rail's current-workspace tile.
 *
 * It carries the workspace's own actions (Members, Invite people, Workspace
 * settings), the list of workspaces with their unread standing, and the two
 * ways in and out of a workspace. Membership actions live here rather than in
 * "+ New", which is strictly about creating conversations.
 *
 * The trigger is this component's default slot, so each anchor keeps its own
 * markup and selector while the surface stays a single implementation. Below
 * `md` it presents as a bottom sheet for the same reason the user menu does: a
 * dropdown anchored inside an off-canvas drawer is neither reachable nor
 * sizeable on a phone.
 */
withDefaults(
    defineProps<{
        /**
         * Which edge the desktop popover leaves its anchor from: the headers
         * drop it downwards, the rail's tile pushes it out sideways so it lands
         * beside the rail rather than over the panel. Ignored below `md`, where
         * the surface is a bottom sheet.
         */
        side?: 'bottom' | 'right';
    }>(),
    { side: 'bottom' },
);

const emit = defineEmits<{
    /** The viewer asked to invite people; the host owns the invite modal. */
    invite: [];
    /** The viewer asked to review their pending invitations. */
    join: [];
}>();

const page = usePage();
const { t } = useTranslations();
const { getInitials } = useInitials();
const { switchTeam } = useTeamSwitch();

const currentTeam = computed<Team | null>(() => page.props.currentTeam);
const teams = computed<Team[]>(() => page.props.teams ?? []);
const canInvite = computed(() => page.props.canInviteToCurrentTeam ?? false);
const canUpdate = computed(() => page.props.canUpdateCurrentTeam ?? false);
const pendingInvitationCount = computed(
    () => (page.props.pendingInvitations ?? []).length,
);

/**
 * Where the two settings rows land. "Members" anchors on the roster section of
 * the workspace settings page rather than opening a surface of its own — the
 * page already holds the member list, the invite button and the role controls.
 */
const settingsHref = computed(() =>
    currentTeam.value ? teamEdit(currentTeam.value.slug).url : '',
);
const membersHref = computed(() =>
    settingsHref.value === '' ? '' : `${settingsHref.value}#members`,
);

const isSheetViewport = useIsMobile();

const open = ref(false);

// A breakpoint crossing while the surface is open resets it, so the sheet
// cannot reappear stale on the way back down (as the user menu does).
watch(isSheetViewport, () => {
    open.value = false;
});

/** Leave the surface before anything else happens: it must never outlive a navigation. */
function close(): void {
    open.value = false;
}

function requestInvite(): void {
    close();
    emit('invite');
}

function requestJoin(): void {
    close();
    emit('join');
}

function chooseTeam(team: Team): void {
    close();

    if (team.id !== currentTeam.value?.id) {
        switchTeam(team);
    }
}

/**
 * How many members a workspace has, as the one line both surfaces render. The
 * count is interpolated rather than concatenated, so a locale is free to put it
 * anywhere in the phrase.
 */
function memberCountLine(team: Team | null): string {
    const count = team?.membersCount ?? 0;

    return count === 1
        ? t(':count member', { count })
        : t(':count members', { count });
}

/** The shared look of one 52px sheet row. */
const sheetRowClass =
    'flex h-13 w-full items-center gap-3 rounded-[13px] px-3 text-left text-base font-normal text-foreground transition-colors hover:bg-muted/50 active:bg-muted';
</script>

<template>
    <Dialog v-if="isSheetViewport" :open="open" @update:open="open = $event">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent
            data-test="workspace-sheet"
            :show-close-button="false"
            class="gap-0 p-2"
        >
            <DialogTitle class="sr-only">{{ $t('Workspaces') }}</DialogTitle>
            <DialogDescription class="sr-only">
                {{ $t('Switch workspace, or manage this one.') }}
            </DialogDescription>

            <div class="flex items-center gap-3 px-3 pt-2 pb-3">
                <span
                    aria-hidden="true"
                    class="flex size-10 shrink-0 items-center justify-center rounded-[11px] bg-sidebar-primary text-[13px] font-semibold text-sidebar-primary-foreground"
                    >{{ getInitials(currentTeam?.name ?? '') }}</span
                >
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-base font-semibold">{{
                        currentTeam?.name ?? $t('Select team')
                    }}</span>
                    <span class="block text-xs text-muted-foreground">{{
                        memberCountLine(currentTeam)
                    }}</span>
                </span>
            </div>

            <Link
                v-if="currentTeam"
                :href="membersHref"
                data-test="workspace-members-link"
                :class="sheetRowClass"
                @click="close"
            >
                <Users class="size-5 text-muted-foreground" />
                <span class="min-w-0 flex-1 truncate">{{ $t('Members') }}</span>
            </Link>
            <Button
                v-if="canInvite"
                variant="unstyled"
                size="none"
                type="button"
                data-test="invite-member-trigger"
                :class="sheetRowClass"
                @click="requestInvite"
            >
                <UserPlus class="size-5 text-muted-foreground" />
                <span class="min-w-0 flex-1 truncate">{{
                    $t('Invite people')
                }}</span>
            </Button>
            <Link
                v-if="canUpdate && currentTeam"
                :href="settingsHref"
                data-test="workspace-settings-link"
                :class="sheetRowClass"
                @click="close"
            >
                <Settings class="size-5 text-muted-foreground" />
                <span class="min-w-0 flex-1 truncate">{{
                    $t('Workspace settings')
                }}</span>
            </Link>

            <div aria-hidden="true" class="mx-3 my-2 h-px bg-border" />

            <div
                class="px-3 pb-1.5 text-[10px] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
            >
                {{ $t('Workspaces') }}
            </div>
            <Button
                v-for="team in teams"
                :key="team.id"
                variant="unstyled"
                size="none"
                type="button"
                data-test="team-switcher-item"
                :data-current="team.isCurrent ? 'true' : undefined"
                :aria-current="team.isCurrent ? 'true' : undefined"
                :class="[sheetRowClass, team.isCurrent ? 'bg-muted' : '']"
                @click="chooseTeam(team)"
            >
                <span
                    aria-hidden="true"
                    class="flex size-8 shrink-0 items-center justify-center rounded-[9px] text-[11px] font-semibold"
                    :class="
                        team.isCurrent
                            ? 'bg-sidebar-primary text-sidebar-primary-foreground'
                            : 'bg-secondary text-secondary-foreground'
                    "
                    >{{ getInitials(team.name) }}</span
                >
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[15px]">{{
                        team.name
                    }}</span>
                    <span class="block text-xs text-muted-foreground">{{
                        memberCountLine(team)
                    }}</span>
                </span>
                <Check
                    v-if="team.isCurrent"
                    class="size-4 shrink-0 text-brass"
                />
                <template v-else>
                    <span
                        v-if="team.mentionCount > 0"
                        data-test="workspace-mention-badge"
                        class="flex h-4.5 min-w-4.5 shrink-0 items-center justify-center rounded-full bg-brass px-1.5 text-[10px] font-bold text-brass-foreground tabular-nums"
                        :aria-label="
                            $t(':count unread mentions', {
                                count: team.mentionCount,
                            })
                        "
                        >{{ team.mentionCount }}</span
                    >
                    <template v-else-if="team.unreadCount > 0">
                        <span
                            aria-hidden="true"
                            data-test="workspace-unread-dot"
                            class="size-1.5 shrink-0 rounded-full bg-brass"
                        />
                        <span class="sr-only">{{ $t('Unread') }}</span>
                    </template>
                </template>
            </Button>

            <div aria-hidden="true" class="mx-3 my-2 h-px bg-border" />

            <CreateTeamModal>
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="team-switcher-new-team"
                    :class="sheetRowClass"
                >
                    <Plus class="size-5 text-muted-foreground" />
                    <span class="min-w-0 flex-1 truncate">{{
                        $t('Create a workspace')
                    }}</span>
                </Button>
            </CreateTeamModal>
            <Button
                v-if="pendingInvitationCount > 0"
                variant="unstyled"
                size="none"
                type="button"
                data-test="join-workspace-trigger"
                :class="sheetRowClass"
                @click="requestJoin"
            >
                <LogIn class="size-5 text-muted-foreground" />
                <span class="min-w-0 flex-1 truncate">{{
                    $t('Join a workspace')
                }}</span>
                <span
                    data-test="join-workspace-count"
                    class="flex h-4.5 min-w-4.5 shrink-0 items-center justify-center rounded-full bg-brass px-1.5 text-[10px] font-bold text-brass-foreground tabular-nums"
                    >{{ pendingInvitationCount }}</span
                >
            </Button>
        </DialogContent>
    </Dialog>

    <DropdownMenu v-else :open="open" @update:open="open = $event">
        <DropdownMenuTrigger as-child>
            <slot />
        </DropdownMenuTrigger>
        <DropdownMenuContent
            data-test="workspace-sheet"
            align="start"
            :side="side"
            class="w-59 rounded-[14px] p-1.5"
        >
            <div class="flex items-center gap-2.5 px-2.5 pt-2 pb-2">
                <span
                    aria-hidden="true"
                    class="flex size-8 shrink-0 items-center justify-center rounded-[9px] bg-sidebar-primary text-[11.5px] font-semibold text-sidebar-primary-foreground"
                    >{{ getInitials(currentTeam?.name ?? '') }}</span
                >
                <span class="min-w-0 flex-1">
                    <span
                        class="block truncate text-[13.5px] font-semibold text-foreground"
                        >{{ currentTeam?.name ?? $t('Select team') }}</span
                    >
                    <span class="block text-[11px] text-muted-foreground">{{
                        memberCountLine(currentTeam)
                    }}</span>
                </span>
            </div>

            <DropdownMenuItem v-if="currentTeam" as-child>
                <Link
                    :href="membersHref"
                    data-test="workspace-members-link"
                    class="cursor-pointer gap-2.25"
                    @click="close"
                >
                    <Users class="size-3.75 text-muted-foreground" />
                    {{ $t('Members') }}
                </Link>
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="canInvite"
                data-test="invite-member-trigger"
                class="cursor-pointer gap-2.25"
                @select="requestInvite"
            >
                <UserPlus class="size-3.75 text-muted-foreground" />
                {{ $t('Invite people') }}
            </DropdownMenuItem>
            <DropdownMenuItem v-if="canUpdate && currentTeam" as-child>
                <Link
                    :href="settingsHref"
                    data-test="workspace-settings-link"
                    class="cursor-pointer gap-2.25"
                    @click="close"
                >
                    <Settings class="size-3.75 text-muted-foreground" />
                    {{ $t('Workspace settings') }}
                </Link>
            </DropdownMenuItem>

            <DropdownMenuSeparator />

            <DropdownMenuLabel
                class="px-2.5 pb-1 text-[10px] font-semibold tracking-[0.1em] text-muted-foreground uppercase"
            >
                {{ $t('Workspaces') }}
            </DropdownMenuLabel>
            <DropdownMenuItem
                v-for="team in teams"
                :key="team.id"
                data-test="team-switcher-item"
                :data-current="team.isCurrent ? 'true' : undefined"
                :aria-current="team.isCurrent ? 'true' : undefined"
                class="cursor-pointer gap-2.5 rounded-[10px] px-2.5 py-1.75 data-[current=true]:bg-secondary"
                @select="chooseTeam(team)"
            >
                <span
                    aria-hidden="true"
                    class="flex size-6.5 shrink-0 items-center justify-center rounded-[8px] text-[10px] font-semibold"
                    :class="
                        team.isCurrent
                            ? 'bg-sidebar-primary text-sidebar-primary-foreground'
                            : 'bg-secondary text-secondary-foreground'
                    "
                    >{{ getInitials(team.name) }}</span
                >
                <span class="min-w-0 flex-1">
                    <span
                        class="block truncate text-[13px]"
                        :class="team.isCurrent ? 'font-semibold' : ''"
                        >{{ team.name }}</span
                    >
                    <span class="block text-[11px] text-muted-foreground">{{
                        memberCountLine(team)
                    }}</span>
                </span>
                <Check
                    v-if="team.isCurrent"
                    class="size-3.5 shrink-0 text-brass"
                />
                <template v-else>
                    <span
                        v-if="team.mentionCount > 0"
                        data-test="workspace-mention-badge"
                        class="flex h-4.25 min-w-4.5 shrink-0 items-center justify-center rounded-full bg-brass px-1.5 text-[10px] font-bold text-brass-foreground tabular-nums"
                        :aria-label="
                            $t(':count unread mentions', {
                                count: team.mentionCount,
                            })
                        "
                        >{{ team.mentionCount }}</span
                    >
                    <template v-else-if="team.unreadCount > 0">
                        <span
                            aria-hidden="true"
                            data-test="workspace-unread-dot"
                            class="size-1.5 shrink-0 rounded-full bg-brass"
                        />
                        <span class="sr-only">{{ $t('Unread') }}</span>
                    </template>
                </template>
            </DropdownMenuItem>

            <DropdownMenuSeparator />

            <CreateTeamModal>
                <DropdownMenuItem
                    data-test="team-switcher-new-team"
                    class="cursor-pointer gap-2.25"
                    @select.prevent
                >
                    <Plus class="size-3.75 text-muted-foreground" />
                    {{ $t('Create a workspace') }}
                </DropdownMenuItem>
            </CreateTeamModal>
            <DropdownMenuItem
                v-if="pendingInvitationCount > 0"
                data-test="join-workspace-trigger"
                class="cursor-pointer gap-2.25"
                @select="requestJoin"
            >
                <LogIn class="size-3.75 text-muted-foreground" />
                {{ $t('Join a workspace') }}
                <span
                    data-test="join-workspace-count"
                    class="ml-auto flex h-4.25 min-w-4.5 items-center justify-center rounded-full bg-brass px-1.5 text-[10px] font-bold text-brass-foreground tabular-nums"
                    >{{ pendingInvitationCount }}</span
                >
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
