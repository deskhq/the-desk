<script setup lang="ts">
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DemoLock from '@/components/DemoLock.vue';
import FormField from '@/components/FormField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { useDemoMode } from '@/composables/useDemoMode';

const { demoMode } = useDemoMode();
</script>

<template>
    <DemoLock v-if="demoMode" v-slot="{ disabled }">
        <Button
            variant="outline"
            class="rounded-full border-destructive/40 text-destructive-text hover:border-destructive/60 hover:bg-destructive/10 hover:text-destructive-text max-md:h-11"
            data-test="delete-user-button"
            :disabled="disabled"
            >{{ $t('Delete account…') }}</Button
        >
    </DemoLock>

    <ConfirmDialog
        v-else
        :title="$t('Are you sure you want to delete your account?')"
        :confirm-label="$t('Delete account')"
        reset-on-success
        :submit="{ form: ProfileController.destroy.form() }"
        confirm-data-test="confirm-delete-user-button"
    >
        <template #trigger>
            <Button
                variant="outline"
                class="rounded-full border-destructive/40 text-destructive-text hover:border-destructive/60 hover:bg-destructive/10 hover:text-destructive-text max-md:h-11"
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
