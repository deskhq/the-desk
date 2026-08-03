<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PasskeyPromptDialog from '@/components/auth/PasskeyPromptDialog.vue';
import CommandPalette from '@/components/CommandPalette.vue';
import DndPauseDialog from '@/components/DndPauseDialog.vue';
import InstallAppDialog from '@/components/InstallAppDialog.vue';
import InviteMemberModal from '@/components/InviteMemberModal.vue';
import KeyboardShortcutsModal from '@/components/KeyboardShortcutsModal.vue';
import NewDirectMessageModal from '@/components/NewDirectMessageModal.vue';
import OnboardingTour from '@/components/OnboardingTour.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import UserStatusDialog from '@/components/UserStatusDialog.vue';
import { useDialog } from '@/composables/useDialog';
import type { RoleOption } from '@/types/teams';

/**
 * Every layer the workspace shell puts over itself, in one place.
 *
 * These used to be ten mount sites in {@see MainLayout}, each with its own open
 * ref or its own emit chain running up through four components to reach the
 * layout that owned the modal. {@see useDialog} took the open state, and this
 * takes the mounting — so the layout is left with the dock, the pane and one
 * tag, and adding a dialog is an entry in the registry plus a mount here.
 *
 * Almost everything a dialog needs is a shared workspace prop it can read
 * itself. The two exceptions arrive as props: presence is a live Reverb
 * subscription, and subscribing a second time here would open a second channel
 * for the same roster.
 */
const emit = defineEmits<{
    /**
     * The post-registration prompt was answered or found unshowable, so whatever
     * was queued behind it may go ahead.
     */
    promptAnswered: [];
}>();

const page = usePage();

// Destructured so each flag is a top-level ref the template unwraps, and
// `v-model:open` binds it as the dialog's own state rather than a copy.
const { isOpen: newMessageOpen } = useDialog('newMessage');
const { isOpen: inviteOpen } = useDialog('invite');
const { isOpen: invitationsOpen } = useDialog('invitations');
const { isOpen: switcherOpen } = useDialog('switcher');
const { isOpen: shortcutsOpen } = useDialog('shortcuts');
const { isOpen: statusOpen } = useDialog('status');
const { isOpen: dndOpen } = useDialog('dnd');
const { isOpen: installOpen } = useDialog('install');

const currentTeam = computed(() => page.props.currentTeam);
const channels = computed(() => page.props.channels ?? []);
const currentUserId = computed(() => String(page.props.auth.user.id));

/** The current team's members, feeding the DM entry points (people picker + ⌘K). */
const teamMembers = computed(() => page.props.teamMembers ?? []);

const pendingInvitations = computed(() => page.props.pendingInvitations ?? []);

/**
 * The invite modal's permission and assignable roles ride along on the shared
 * workspace props, the same ones the workspace sheet's row is gated on.
 */
const canInviteToCurrentTeam = computed(
    () => page.props.canInviteToCurrentTeam ?? false,
);
const invitableRoles = computed<RoleOption[]>(
    () => page.props.invitableRoles ?? [],
);

/**
 * The one-time security prompt owed to an account registered in this session.
 * While one is pending the prompt owns the first paint and the tour waits behind
 * it; the prompt hands over once it is answered or found unshowable.
 */
const postRegistrationPrompt = computed(
    () => page.props.postRegistrationPrompt ?? null,
);
</script>

<template>
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

    <CommandPalette
        v-if="currentTeam"
        v-model:open="switcherOpen"
        :channels="channels"
        :members="teamMembers"
        :current-user-id="currentUserId"
        :team-slug="currentTeam.slug"
    />

    <NewDirectMessageModal
        v-if="currentTeam"
        v-model:open="newMessageOpen"
        :members="teamMembers"
        :current-user-id="currentUserId"
        :team-slug="currentTeam.slug"
    />

    <KeyboardShortcutsModal v-model:open="shortcutsOpen" />

    <UserStatusDialog v-model:open="statusOpen" />

    <InstallAppDialog v-model:open="installOpen" />

    <DndPauseDialog v-model:open="dndOpen" />

    <!-- The post-registration security prompt goes ahead of the tour, and starts
         it on its way out. Mounted only while one is queued, so an ordinary
         sign-in reaches the tour exactly as it always did. -->
    <PasskeyPromptDialog
        v-if="postRegistrationPrompt === 'passkey'"
        @done="emit('promptAnswered')"
    />

    <OnboardingTour />
</template>
