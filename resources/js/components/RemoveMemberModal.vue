<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { destroy as destroyMember } from '@/routes/teams/members';
import type { Team, TeamMember } from '@/types';

type Props = {
    team: Team;
    member: TeamMember | null;
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();
</script>

<template>
    <ConfirmDialog
        v-if="props.member"
        :open="props.open"
        :title="$t('Remove team member')"
        :confirm-label="$t('Remove member')"
        :submit="{ visit: destroyMember([props.team.slug, props.member.id]) }"
        confirm-data-test="remove-member-confirm"
        @update:open="emit('update:open', $event)"
    >
        <template #description>
            {{ $t('Are you sure you want to remove') }}
            <strong>{{ props.member.name }}</strong>
            {{ $t('from this team?') }}
        </template>
    </ConfirmDialog>
</template>
