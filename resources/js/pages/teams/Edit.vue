<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CancelInvitationModal from '@/components/CancelInvitationModal.vue';
import DeleteTeamModal from '@/components/DeleteTeamModal.vue';
import InviteMemberModal from '@/components/InviteMemberModal.vue';
import RemoveMemberModal from '@/components/RemoveMemberModal.vue';
import AdminLinks from '@/components/teams/AdminLinks.vue';
import ChannelCreationForm from '@/components/teams/ChannelCreationForm.vue';
import DangerZone from '@/components/teams/DangerZone.vue';
import DefaultChannelsForm from '@/components/teams/DefaultChannelsForm.vue';
import MemberRoster from '@/components/teams/MemberRoster.vue';
import PendingInvitations from '@/components/teams/PendingInvitations.vue';
import SettingsHeader from '@/components/teams/SettingsHeader.vue';
import TeamNameForm from '@/components/teams/TeamNameForm.vue';
import TransferOwnershipModal from '@/components/TransferOwnershipModal.vue';
import { useTranslations } from '@/composables/useTranslations';
import { translate } from '@/lib/i18n';
import { edit, index } from '@/routes/teams';
import { resend as resendInvitationRoute } from '@/routes/teams/invitations';
import { update as updateMember } from '@/routes/teams/members';
import type {
    ChannelCreationSettings,
    DefaultChannelCandidate,
    RoleOption,
    Team,
    TeamInvitation,
    TeamMember,
    TeamPermissions,
} from '@/types';

type Props = {
    team: Team;
    members: TeamMember[];
    invitations: TeamInvitation[];
    permissions: TeamPermissions;
    availableRoles: RoleOption[];
    /** The workspace's channel-creation policies and the options for each. */
    channelCreation: ChannelCreationSettings;
    /** The public channels an admin may default; empty for everyone else. */
    defaultChannels: DefaultChannelCandidate[];
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

const { t } = useTranslations();

const page = usePage();
const currentUserId = computed(() => String(page.props.auth.user.id));

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
        <SettingsHeader :team="team" />

        <TeamNameForm v-if="permissions.canUpdateTeam" :team="team" />

        <ChannelCreationForm
            v-if="permissions.canUpdateTeam"
            :team="team"
            :settings="channelCreation"
        />

        <DefaultChannelsForm
            v-if="permissions.canUpdateTeam"
            :team="team"
            :channels="defaultChannels"
        />

        <MemberRoster
            :members="members"
            :team-slug="team.slug"
            :current-user-id="currentUserId"
            :permissions="permissions"
            :available-roles="availableRoles"
            @invite="inviteDialogOpen = true"
            @update-role="updateMemberRole"
            @transfer-ownership="confirmTransferOwnership"
            @remove="confirmRemoveMember"
        />

        <PendingInvitations
            v-if="invitations.length > 0"
            :invitations="invitations"
            :permissions="permissions"
            @resend="resendInvitation"
            @cancel="confirmCancelInvitation"
        />

        <AdminLinks :team="team" :permissions="permissions" />

        <DangerZone
            v-if="permissions.canDeleteTeam && !team.isPersonal"
            @delete="deleteDialogOpen = true"
        />
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
