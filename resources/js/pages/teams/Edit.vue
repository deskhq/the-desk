<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ChartColumn,
    Check,
    ChevronDown,
    ChevronRight,
    Crown,
    Download,
    Mail,
    Plug,
    ScrollText,
    Send,
    ShieldCheck,
    SmilePlus,
    UserPlus,
    Users,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CancelInvitationModal from '@/components/CancelInvitationModal.vue';
import DeleteTeamModal from '@/components/DeleteTeamModal.vue';
import DemoLock from '@/components/DemoLock.vue';
import InputError from '@/components/InputError.vue';
import InviteMemberModal from '@/components/InviteMemberModal.vue';
import RemoveMemberModal from '@/components/RemoveMemberModal.vue';
import TransferOwnershipModal from '@/components/TransferOwnershipModal.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import UserStatusEmoji from '@/components/UserStatusEmoji.vue';
import { useDemoMode } from '@/composables/useDemoMode';
import { useInitials } from '@/composables/useInitials';
import { useTranslations } from '@/composables/useTranslations';
import { formatRelativeTime } from '@/lib/datetime';
import { translate } from '@/lib/i18n';
import { edit, index, update } from '@/routes/teams';
import { index as analyticsIndex } from '@/routes/teams/analytics';
import { index as auditIndex } from '@/routes/teams/audit';
import { index as auditExportsIndex } from '@/routes/teams/audit-exports';
import { index as emojisIndex } from '@/routes/teams/emojis';
import { index as groupsIndex } from '@/routes/teams/groups';
import { index as integrationsIndex } from '@/routes/teams/integrations';
import { resend as resendInvitationRoute } from '@/routes/teams/invitations';
import {
    show as showMember,
    update as updateMember,
} from '@/routes/teams/members';
import { index as securityLogIndex } from '@/routes/teams/security-log';
import type {
    RoleOption,
    Team,
    TeamInvitation,
    TeamMember,
    TeamPermissions,
    TeamRole,
} from '@/types';

type Props = {
    team: Team;
    members: TeamMember[];
    invitations: TeamInvitation[];
    permissions: TeamPermissions;
    availableRoles: RoleOption[];
};

const props = defineProps<Props>();

defineOptions({
    layout: (props: { team: Team }) => ({
        breadcrumbs: [
            {
                title: translate('Teams'),
                href: index(),
            },
            {
                title: props.team.name,
                href: edit(props.team.slug),
            },
        ],
    }),
});

const { getInitials } = useInitials();
const { t } = useTranslations();
const { demoMode } = useDemoMode();

const page = usePage();
const currentUserId = computed(() => String(page.props.auth.user.id));

// The integrations card shows only for managers (Owner + Admin) and only while
// the platform toggle is on, mirroring the route's own gating.
const showIntegrationsLink = computed(
    () =>
        props.permissions.canManageIntegrations &&
        page.props.integrationsEnabled,
);

const inviteDialogOpen = ref(false);
const deleteDialogOpen = ref(false);
const removeMemberDialogOpen = ref(false);
const memberToRemove = ref<TeamMember | null>(null);
const transferOwnershipDialogOpen = ref(false);
const memberToPromote = ref<TeamMember | null>(null);
const cancelInvitationDialogOpen = ref(false);
const invitationToCancel = ref<TeamInvitation | null>(null);

const pageTitle = computed(() =>
    props.permissions.canUpdateTeam
        ? t('Edit :name', { name: props.team.name })
        : t('View :name', { name: props.team.name }),
);

const isOwner = computed(() => props.team.role === 'owner');

const isCurrentUser = (member: TeamMember): boolean =>
    String(member.id) === currentUserId.value;

/**
 * The one-line description of what each assignable role can do, shown under the
 * role name inside the role dropdown. Keyed by role so the copy stays with the
 * permission it describes.
 */
const roleDescription = (role: TeamRole): string => {
    switch (role) {
        case 'admin':
            return t('Can manage members, channels, and invitations');
        case 'member':
            return t('Can read and post in channels they belong to');
        default:
            return '';
    }
};

const updateMemberRole = (member: TeamMember, newRole: string) => {
    router.visit(updateMember([props.team.slug, member.id]), {
        data: { role: newRole },
        preserveScroll: true,
    });
};

const confirmRemoveMember = (member: TeamMember) => {
    memberToRemove.value = member;
    removeMemberDialogOpen.value = true;
};

const confirmCancelInvitation = (invitation: TeamInvitation) => {
    invitationToCancel.value = invitation;
    cancelInvitationDialogOpen.value = true;
};

const resendInvitation = (invitation: TeamInvitation) => {
    router.visit(resendInvitationRoute([props.team.slug, invitation.code]), {
        preserveScroll: true,
    });
};

const confirmTransferOwnership = (member: TeamMember) => {
    memberToPromote.value = member;
    transferOwnershipDialogOpen.value = true;
};
</script>

<template>
    <Head :title="pageTitle" />

    <div>
        <!-- Page header -->
        <header class="border-b border-border pb-6">
            <nav
                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                :aria-label="$t('breadcrumb')"
            >
                <Link :href="index()" class="hover:text-foreground">
                    {{ $t('Teams') }}
                </Link>
                <ChevronRight class="h-3 w-3 opacity-60" />
                <span class="font-medium text-foreground/70">{{
                    team.name
                }}</span>
            </nav>

            <div class="mt-2 flex flex-wrap items-end gap-3">
                <div class="min-w-0">
                    <h1
                        class="font-serif text-3xl font-semibold tracking-tight"
                    >
                        {{ team.name }}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{
                            $t(
                                "Manage this team's name, members, and ownership",
                            )
                        }}
                    </p>
                </div>

                <span
                    v-if="isOwner"
                    class="ml-auto inline-flex items-center gap-1.5 rounded-full border border-brass-border bg-brass-fill px-3 py-1 text-[11px] font-semibold tracking-wide text-brass-fill-foreground uppercase"
                >
                    <Crown class="h-3 w-3 text-brass" />
                    {{ $t('You own this team') }}
                </span>
            </div>
        </header>

        <!-- Team name -->
        <section
            v-if="permissions.canUpdateTeam"
            class="border-b border-border py-6"
        >
            <div class="mb-4">
                <h2 class="font-serif text-lg font-semibold">
                    {{ $t('Team name') }}
                </h2>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    {{ $t('Shown in the team switcher and on invitations') }}
                </p>
            </div>

            <Form
                v-bind="update.form(team.slug)"
                v-slot="{ errors, processing }"
            >
                <div class="flex flex-wrap items-start gap-3">
                    <div class="w-full max-w-sm">
                        <Input
                            id="name"
                            name="name"
                            data-test="team-name-input"
                            :default-value="team.name"
                            :aria-label="$t('Team name')"
                            class="rounded-lg"
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <DemoLock v-slot="{ disabled }">
                        <Button
                            type="submit"
                            class="rounded-full px-6"
                            data-test="team-save-button"
                            :disabled="processing || disabled"
                        >
                            {{ $t('Save') }}
                        </Button>
                    </DemoLock>
                </div>
            </Form>
        </section>

        <!-- Team members -->
        <section class="border-b border-border py-6">
            <div class="mb-4 flex flex-wrap items-start gap-3">
                <div class="min-w-0">
                    <h2 class="font-serif text-lg font-semibold">
                        {{ $t('Team members') }}
                        <span class="font-normal text-muted-foreground"
                            >&middot; {{ members.length }}</span
                        >
                    </h2>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        {{
                            $t(
                                'Manage who belongs to this team and what they can do',
                            )
                        }}
                    </p>
                </div>

                <Button
                    v-if="permissions.canCreateInvitation"
                    class="ml-auto rounded-full"
                    data-test="invite-member-button"
                    @click="inviteDialogOpen = true"
                >
                    <UserPlus /> {{ $t('Invite member') }}
                </Button>
            </div>

            <div class="flex flex-col gap-2">
                <div
                    v-for="member in members"
                    :key="member.id"
                    data-test="member-row"
                    class="flex flex-wrap items-center gap-4 rounded-xl border border-border bg-card p-3.5 shadow-[0_2px_8px_rgba(29,26,21,0.05)]"
                >
                    <Avatar class="h-10 w-10 shrink-0">
                        <AvatarImage
                            v-if="member.avatar"
                            :src="member.avatar"
                            :alt="member.name"
                        />
                        <AvatarFallback
                            :class="
                                member.role === 'owner'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-muted-foreground'
                            "
                            class="text-xs font-semibold"
                            >{{ getInitials(member.name) }}</AvatarFallback
                        >
                    </Avatar>

                    <div class="min-w-0">
                        <Link
                            :href="showMember([team.slug, member.id])"
                            class="font-semibold underline-offset-4 hover:underline"
                            data-test="member-profile-link"
                        >
                            {{ member.name }}
                            <span
                                v-if="isCurrentUser(member)"
                                class="text-sm font-medium text-muted-foreground"
                                >{{ $t('(you)') }}</span
                            >
                            <UserStatusEmoji
                                :status="member.status"
                                :name="member.name"
                                class="align-[-1px] text-xs"
                            />
                        </Link>
                        <div
                            v-if="member.email"
                            class="truncate text-sm text-muted-foreground"
                        >
                            {{ member.email }}
                        </div>
                        <div
                            v-if="member.status?.text"
                            data-test="member-status-text"
                            class="truncate text-sm text-muted-foreground"
                        >
                            {{ member.status.text }}
                        </div>
                    </div>

                    <div class="ml-auto flex items-center gap-1.5">
                        <span
                            v-if="member.role === 'owner'"
                            class="inline-flex items-center gap-1.5 rounded-full border border-brass-border bg-brass-fill px-3 py-1 text-xs font-semibold text-brass-fill-foreground"
                        >
                            <Crown class="h-3 w-3 text-brass" />
                            {{ member.role_label }}
                        </span>

                        <DropdownMenu v-else-if="permissions.canUpdateMember">
                            <DropdownMenuTrigger as-child>
                                <Button
                                    data-test="member-role-trigger"
                                    variant="outline"
                                    size="sm"
                                    class="rounded-full"
                                >
                                    {{ member.role_label }}
                                    <ChevronDown
                                        class="ml-1 h-3.5 w-3.5 opacity-50"
                                    />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-64">
                                <DropdownMenuItem
                                    v-for="role in availableRoles"
                                    :key="role.value"
                                    data-test="member-role-option"
                                    class="flex-col items-start gap-0.5"
                                    @click="
                                        updateMemberRole(member, role.value)
                                    "
                                >
                                    <span
                                        class="flex w-full items-center gap-1.5 font-medium"
                                    >
                                        {{ role.label }}
                                        <Check
                                            v-if="member.role === role.value"
                                            class="ml-auto h-3.5 w-3.5 text-brass"
                                        />
                                    </span>
                                    <span class="text-xs text-muted-foreground">
                                        {{ roleDescription(role.value) }}
                                    </span>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <span
                            v-else
                            class="rounded-full border border-border px-3 py-1 text-xs font-semibold"
                        >
                            {{ member.role_label }}
                        </span>

                        <TooltipProvider
                            v-if="
                                member.role !== 'owner' &&
                                permissions.canTransferOwnership
                            "
                        >
                            <Tooltip>
                                <!-- Off the demo the tooltip triggers on the
                                     focusable button (keyboard + hover); in the
                                     demo the button is disabled, so a
                                     tabindex'd span carries the trigger to keep
                                     the reason reachable by keyboard. -->
                                <TooltipTrigger v-if="demoMode" as-child>
                                    <span
                                        tabindex="0"
                                        class="inline-flex cursor-not-allowed"
                                    >
                                        <Button
                                            data-test="member-transfer-ownership-button"
                                            variant="ghost"
                                            size="icon"
                                            class="rounded-full text-muted-foreground"
                                            disabled
                                        >
                                            <Crown class="h-4 w-4" />
                                        </Button>
                                    </span>
                                </TooltipTrigger>
                                <TooltipTrigger v-else as-child>
                                    <Button
                                        data-test="member-transfer-ownership-button"
                                        variant="ghost"
                                        size="icon"
                                        class="rounded-full text-muted-foreground"
                                        @click="
                                            confirmTransferOwnership(member)
                                        "
                                    >
                                        <Crown class="h-4 w-4" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>
                                        {{
                                            demoMode
                                                ? $t('Disabled in the demo')
                                                : $t('Transfer ownership')
                                        }}
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>

                        <TooltipProvider
                            v-if="
                                member.role !== 'owner' &&
                                permissions.canRemoveMember
                            "
                        >
                            <Tooltip>
                                <!-- Same focusable-trigger split as the transfer
                                     button above: enabled button off the demo,
                                     tabindex'd span around the disabled button
                                     in the demo. -->
                                <TooltipTrigger v-if="demoMode" as-child>
                                    <span
                                        tabindex="0"
                                        class="inline-flex cursor-not-allowed"
                                    >
                                        <Button
                                            data-test="member-remove-button"
                                            variant="ghost"
                                            size="icon"
                                            class="rounded-full text-muted-foreground"
                                            disabled
                                        >
                                            <X class="h-4 w-4" />
                                        </Button>
                                    </span>
                                </TooltipTrigger>
                                <TooltipTrigger v-else as-child>
                                    <Button
                                        data-test="member-remove-button"
                                        variant="ghost"
                                        size="icon"
                                        class="rounded-full text-muted-foreground"
                                        @click="confirmRemoveMember(member)"
                                    >
                                        <X class="h-4 w-4" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>
                                        {{
                                            demoMode
                                                ? $t('Disabled in the demo')
                                                : $t('Remove member')
                                        }}
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                </div>
            </div>
        </section>

        <!-- Pending invitations -->
        <section
            v-if="invitations.length > 0"
            class="border-b border-border py-6"
        >
            <div class="mb-4">
                <h2 class="font-serif text-lg font-semibold">
                    {{ $t('Pending invitations') }}
                    <span class="font-normal text-muted-foreground"
                        >&middot; {{ invitations.length }}</span
                    >
                </h2>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    {{
                        $t(
                            'Sent but not yet accepted. They expire after 3 days',
                        )
                    }}
                </p>
            </div>

            <div class="flex flex-col gap-2">
                <div
                    v-for="invitation in invitations"
                    :key="invitation.code"
                    data-test="invitation-row"
                    class="flex flex-wrap items-center gap-4 rounded-xl border border-dashed border-border bg-muted/30 p-3.5"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted"
                    >
                        <Mail class="h-5 w-5 text-muted-foreground" />
                    </div>

                    <div class="min-w-0">
                        <div
                            class="truncate font-semibold text-muted-foreground"
                        >
                            {{ invitation.email }}
                        </div>
                        <div class="text-sm text-muted-foreground">
                            {{
                                $t('Invited as :role · :time', {
                                    role: invitation.role_label,
                                    time: formatRelativeTime(
                                        invitation.created_at,
                                    ),
                                })
                            }}
                        </div>
                    </div>

                    <div class="ml-auto flex items-center gap-1.5">
                        <Button
                            v-if="permissions.canCreateInvitation"
                            data-test="invitation-resend-button"
                            variant="outline"
                            size="sm"
                            class="rounded-full"
                            @click="resendInvitation(invitation)"
                        >
                            <Send class="h-3.5 w-3.5" /> {{ $t('Resend') }}
                        </Button>

                        <TooltipProvider v-if="permissions.canCancelInvitation">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        data-test="invitation-cancel-button"
                                        variant="ghost"
                                        size="icon"
                                        class="rounded-full text-muted-foreground"
                                        @click="
                                            confirmCancelInvitation(invitation)
                                        "
                                    >
                                        <X class="h-4 w-4" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>{{ $t('Cancel invitation') }}</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                </div>
            </div>
        </section>

        <!-- Custom emoji + admin links -->
        <section
            v-if="
                permissions.canViewAnalytics ||
                permissions.canViewAudit ||
                permissions.canViewSecurityLog ||
                permissions.canManageUserGroups ||
                showIntegrationsLink
            "
            class="border-b border-border py-6"
        >
            <div class="grid gap-3 sm:grid-cols-2">
                <Link
                    :href="emojisIndex(team.slug)"
                    data-test="manage-emoji-link"
                    class="flex items-center gap-3 rounded-xl border border-border bg-card p-4 transition-colors hover:border-brass-border"
                >
                    <SmilePlus class="h-4 w-4 shrink-0 text-brass" />
                    <div class="min-w-0">
                        <div class="text-sm font-semibold">
                            {{ $t('Custom emoji') }}
                        </div>
                        <div class="truncate text-xs text-muted-foreground">
                            {{ $t('Named emoji for messages and reactions') }}
                        </div>
                    </div>
                    <ChevronRight
                        class="ml-auto h-3.5 w-3.5 shrink-0 text-muted-foreground"
                    />
                </Link>

                <Link
                    v-if="permissions.canManageUserGroups"
                    :href="groupsIndex(team.slug)"
                    data-test="manage-groups-link"
                    class="flex items-center gap-3 rounded-xl border border-border bg-card p-4 transition-colors hover:border-brass-border"
                >
                    <Users class="h-4 w-4 shrink-0 text-brass" />
                    <div class="min-w-0">
                        <div class="text-sm font-semibold">
                            {{ $t('User groups') }}
                        </div>
                        <div class="truncate text-xs text-muted-foreground">
                            {{ $t('Mentionable aliases for a set of people') }}
                        </div>
                    </div>
                    <ChevronRight
                        class="ml-auto h-3.5 w-3.5 shrink-0 text-muted-foreground"
                    />
                </Link>

                <Link
                    v-if="showIntegrationsLink"
                    :href="integrationsIndex(team.slug)"
                    data-test="manage-integrations-link"
                    class="flex items-center gap-3 rounded-xl border border-border bg-card p-4 transition-colors hover:border-brass-border"
                >
                    <Plug class="h-4 w-4 shrink-0 text-brass" />
                    <div class="min-w-0">
                        <div class="text-sm font-semibold">
                            {{ $t('Integrations') }}
                        </div>
                        <div class="truncate text-xs text-muted-foreground">
                            {{ $t('Bots, API tokens, and webhooks') }}
                        </div>
                    </div>
                    <ChevronRight
                        class="ml-auto h-3.5 w-3.5 shrink-0 text-muted-foreground"
                    />
                </Link>

                <Link
                    v-if="permissions.canViewAnalytics"
                    :href="analyticsIndex(team.slug)"
                    data-test="view-analytics-link"
                    class="flex items-center gap-3 rounded-xl border border-border bg-card p-4 transition-colors hover:border-brass-border"
                >
                    <ChartColumn class="h-4 w-4 shrink-0 text-brass" />
                    <div class="min-w-0">
                        <div class="text-sm font-semibold">
                            {{ $t('Analytics') }}
                        </div>
                        <div class="truncate text-xs text-muted-foreground">
                            {{ $t('Activity, growth, busiest channels') }}
                        </div>
                    </div>
                    <ChevronRight
                        class="ml-auto h-3.5 w-3.5 shrink-0 text-muted-foreground"
                    />
                </Link>

                <Link
                    v-if="permissions.canViewAudit"
                    :href="auditIndex(team.slug)"
                    data-test="view-audit-log-link"
                    class="flex items-center gap-3 rounded-xl border border-border bg-card p-4 transition-colors hover:border-brass-border"
                >
                    <ScrollText class="h-4 w-4 shrink-0 text-brass" />
                    <div class="min-w-0">
                        <div class="text-sm font-semibold">
                            {{ $t('Audit log') }}
                        </div>
                        <div class="truncate text-xs text-muted-foreground">
                            {{ $t('Moderation and admin actions') }}
                        </div>
                    </div>
                    <ChevronRight
                        class="ml-auto h-3.5 w-3.5 shrink-0 text-muted-foreground"
                    />
                </Link>

                <Link
                    v-if="permissions.canViewSecurityLog"
                    :href="securityLogIndex(team.slug)"
                    data-test="view-security-log-link"
                    class="flex items-center gap-3 rounded-xl border border-border bg-card p-4 transition-colors hover:border-brass-border"
                >
                    <ShieldCheck class="h-4 w-4 shrink-0 text-brass" />
                    <div class="min-w-0">
                        <div class="text-sm font-semibold">
                            {{ $t('Security log') }}
                        </div>
                        <div class="truncate text-xs text-muted-foreground">
                            {{ $t('Sign-ins and credential changes') }}
                        </div>
                    </div>
                    <ChevronRight
                        class="ml-auto h-3.5 w-3.5 shrink-0 text-muted-foreground"
                    />
                </Link>

                <Link
                    v-if="
                        permissions.canViewAudit ||
                        permissions.canViewSecurityLog
                    "
                    :href="auditExportsIndex(team.slug)"
                    data-test="view-audit-exports-link"
                    class="flex items-center gap-3 rounded-xl border border-border bg-card p-4 transition-colors hover:border-brass-border"
                >
                    <Download class="h-4 w-4 shrink-0 text-brass" />
                    <div class="min-w-0">
                        <div class="text-sm font-semibold">
                            {{ $t('Exports') }}
                        </div>
                        <div class="truncate text-xs text-muted-foreground">
                            {{ $t('Export the audit and security logs') }}
                        </div>
                    </div>
                    <ChevronRight
                        class="ml-auto h-3.5 w-3.5 shrink-0 text-muted-foreground"
                    />
                </Link>
            </div>
        </section>

        <!-- Emoji-only fallback for members without admin links -->
        <section v-else class="border-b border-border py-6">
            <Link
                :href="emojisIndex(team.slug)"
                data-test="manage-emoji-link"
                class="flex items-center gap-3 rounded-xl border border-border bg-card p-4 transition-colors hover:border-brass-border sm:max-w-sm"
            >
                <SmilePlus class="h-4 w-4 shrink-0 text-brass" />
                <div class="min-w-0">
                    <div class="text-sm font-semibold">
                        {{ $t('Custom emoji') }}
                    </div>
                    <div class="truncate text-xs text-muted-foreground">
                        {{ $t('Named emoji for messages and reactions') }}
                    </div>
                </div>
                <ChevronRight
                    class="ml-auto h-3.5 w-3.5 shrink-0 text-muted-foreground"
                />
            </Link>
        </section>

        <!-- Danger zone -->
        <section
            v-if="permissions.canDeleteTeam && !team.isPersonal"
            class="py-6"
        >
            <div class="mb-3">
                <h2
                    class="font-serif text-lg font-semibold text-destructive-text"
                >
                    {{ $t('Delete team') }}
                </h2>
                <p class="mt-0.5 max-w-xl text-sm text-muted-foreground">
                    {{
                        $t(
                            'Permanently delete this team and all of its data. This cannot be undone.',
                        )
                    }}
                </p>
            </div>
            <DemoLock v-slot="{ disabled }">
                <Button
                    data-test="delete-team-button"
                    variant="outline"
                    class="rounded-full border-destructive/40 text-destructive-text hover:border-destructive/60 hover:bg-destructive/10 hover:text-destructive-text"
                    :disabled="disabled"
                    @click="deleteDialogOpen = true"
                    >{{ $t('Delete team…') }}</Button
                >
            </DemoLock>
        </section>
    </div>

    <InviteMemberModal
        v-if="permissions.canCreateInvitation"
        :team="team"
        :available-roles="availableRoles"
        :open="inviteDialogOpen"
        @update:open="inviteDialogOpen = $event"
    />

    <RemoveMemberModal
        :team="team"
        :member="memberToRemove"
        :open="removeMemberDialogOpen"
        @update:open="removeMemberDialogOpen = $event"
    />

    <TransferOwnershipModal
        :team="team"
        :member="memberToPromote"
        :open="transferOwnershipDialogOpen"
        @update:open="transferOwnershipDialogOpen = $event"
    />

    <CancelInvitationModal
        :team="team"
        :invitation="invitationToCancel"
        :open="cancelInvitationDialogOpen"
        @update:open="cancelInvitationDialogOpen = $event"
    />

    <DeleteTeamModal
        v-if="permissions.canDeleteTeam && !team.isPersonal"
        :team="team"
        :open="deleteDialogOpen"
        @update:open="deleteDialogOpen = $event"
    />
</template>
