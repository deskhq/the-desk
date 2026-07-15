<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import { Input } from '@/components/ui/input';
import { destroy } from '@/routes/teams';
import type { Team } from '@/types';

type Props = {
    team: Team;
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const confirmationName = ref('');

const canDeleteTeam = computed(() => {
    return confirmationName.value === props.team.name;
});

// Clear the typed confirmation whenever the dialog closes so a reopen starts empty.
watch(
    () => props.open,
    (open) => {
        if (!open) {
            confirmationName.value = '';
        }
    },
);
</script>

<template>
    <ConfirmDialog
        :open="props.open"
        :title="$t('Are you sure?')"
        :confirm-label="$t('Delete team')"
        :submit="{ form: destroy.form(props.team.slug) }"
        :confirm-disabled="!canDeleteTeam"
        confirm-data-test="delete-team-confirm"
        @update:open="emit('update:open', $event)"
    >
        <template #description>
            {{
                $t(
                    'This action cannot be undone. This will permanently delete the team',
                )
            }}
            <strong>"{{ props.team.name }}"</strong>.
        </template>

        <template #body="{ errors }">
            <div class="space-y-4 py-4">
                <FormField id="confirmation-name" :error="errors.name">
                    <template #label>
                        {{ $t('Type') }}
                        <strong>"{{ props.team.name }}"</strong>
                        {{ $t('to confirm') }}
                    </template>
                    <template #default="{ id }">
                        <Input
                            :id="id"
                            v-model="confirmationName"
                            name="name"
                            data-test="delete-team-name"
                            :placeholder="$t('Enter team name')"
                            autocomplete="off"
                        />
                    </template>
                </FormField>
            </div>
        </template>
    </ConfirmDialog>
</template>
