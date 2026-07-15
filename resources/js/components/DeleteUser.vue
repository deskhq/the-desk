<script setup lang="ts">
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
</script>

<template>
    <ConfirmDialog
        :title="$t('Are you sure you want to delete your account?')"
        :confirm-label="$t('Delete account')"
        reset-on-success
        :submit="{ form: ProfileController.destroy.form() }"
        confirm-data-test="confirm-delete-user-button"
    >
        <template #trigger>
            <Button
                variant="outline"
                class="rounded-full border-destructive/40 text-destructive hover:border-destructive/60 hover:bg-destructive/10 hover:text-destructive"
                data-test="delete-user-button"
                >{{ $t('Delete account…') }}</Button
            >
        </template>

        <template #description>
            {{
                $t(
                    'Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
                )
            }}
        </template>

        <template #body="{ errors }">
            <FormField
                id="password"
                :label="$t('Password')"
                label-class="sr-only"
                :error="errors.password"
                v-slot="{ id }"
            >
                <PasswordInput
                    :id="id"
                    name="password"
                    :placeholder="$t('Password')"
                />
            </FormField>
        </template>
    </ConfirmDialog>
</template>
