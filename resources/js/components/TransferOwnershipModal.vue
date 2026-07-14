<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { transferOwnership } from '@/routes/teams/members';
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
        :title="$t('Transfer team ownership')"
        :confirm-label="$t('Transfer ownership')"
        confirm-variant="default"
        reset-on-success
        :submit="{
            form: transferOwnership.form([props.team.slug, props.member.id]),
        }"
        confirm-data-test="transfer-ownership-confirm"
        @update:open="emit('update:open', $event)"
    >
        <template #description>
            {{ $t('Ownership of this team will be transferred to') }}
            <strong>{{ props.member.name }}</strong
            >.
            {{
                $t(
                    'You will be demoted to Admin and can no longer manage ownership. Enter your password to confirm.',
                )
            }}
        </template>

        <template #body="{ errors }">
            <FormField
                id="transfer-password"
                :label="$t('Password')"
                label-class="sr-only"
                :error="errors.password"
                v-slot="{ id }"
            >
                <PasswordInput
                    :id="id"
                    name="password"
                    data-test="transfer-ownership-password"
                    :placeholder="$t('Password')"
                />
            </FormField>
        </template>
    </ConfirmDialog>
</template>
