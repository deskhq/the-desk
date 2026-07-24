<script setup lang="ts">
import { computed } from 'vue';
import SessionController from '@/actions/App/Http/Controllers/Settings/SessionController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DemoLock from '@/components/DemoLock.vue';
import FormField from '@/components/FormField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { useDemoMode } from '@/composables/useDemoMode';
import type { ActiveSession } from '@/types';

type Props = {
    sessions: ActiveSession[];
};

const props = defineProps<Props>();

const { demoMode } = useDemoMode();

// Only offer the control when there is at least one session other than the one
// making the request; the current session can never be revoked from here.
const hasOtherSessions = computed(() =>
    props.sessions.some((session) => !session.isCurrentDevice),
);
</script>

<template>
    <DemoLock v-if="hasOtherSessions && demoMode" v-slot="{ disabled }">
        <Button
            variant="outline"
            class="h-8 rounded-full px-4 text-xs font-semibold max-md:h-11"
            :disabled="disabled"
            data-test="revoke-others-button"
        >
            {{ $t('Log out other devices') }}
        </Button>
    </DemoLock>

    <ConfirmDialog
        v-else-if="hasOtherSessions"
        :title="$t('Log out other devices?')"
        :confirm-label="$t('Log out other devices')"
        reset-on-success
        :submit="{ form: SessionController.destroyOthers.form() }"
        confirm-data-test="confirm-revoke-others"
    >
        <template #trigger>
            <Button
                variant="outline"
                class="h-8 rounded-full px-4 text-xs font-semibold max-md:h-11"
                data-test="revoke-others-button"
            >
                {{ $t('Log out other devices') }}
            </Button>
        </template>

        <template #description>
            {{
                $t(
                    'This logs out every session except the one you are using now. Enter your password to confirm.',
                )
            }}
        </template>

        <template #body="{ errors }">
            <FormField
                id="revoke_others_password"
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
