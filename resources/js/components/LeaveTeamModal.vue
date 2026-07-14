<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { leave as leaveTeamAction } from '@/routes/teams';
import type { Team } from '@/types';

type Props = {
    team: Team | null;
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();
</script>

<template>
    <ConfirmDialog
        v-if="props.team"
        :open="props.open"
        :title="$t('Leave team')"
        :confirm-label="$t('Leave team')"
        :submit="{ visit: leaveTeamAction(props.team.slug) }"
        confirm-data-test="leave-team-confirm"
        @update:open="emit('update:open', $event)"
    >
        <template #description>
            {{ $t('Are you sure you want to leave') }}
            <strong>{{ props.team.name }}</strong
            >?
        </template>
    </ConfirmDialog>
</template>
