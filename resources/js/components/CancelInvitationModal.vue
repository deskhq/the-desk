<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { destroy as destroyInvitation } from '@/routes/teams/invitations';
import type { Team, TeamInvitation } from '@/types';

type Props = {
    team: Team;
    invitation: TeamInvitation | null;
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();
</script>

<template>
    <ConfirmDialog
        v-if="props.invitation"
        :open="props.open"
        :title="$t('Cancel invitation')"
        :confirm-label="$t('Cancel invitation')"
        :cancel-label="$t('Keep invitation')"
        :submit="{
            visit: destroyInvitation([props.team.slug, props.invitation.code]),
        }"
        confirm-data-test="cancel-invitation-confirm"
        @update:open="emit('update:open', $event)"
    >
        <template #description>
            {{ $t('Are you sure you want to cancel the invitation for') }}
            <strong>{{ props.invitation.email }}</strong
            >?
        </template>
    </ConfirmDialog>
</template>
