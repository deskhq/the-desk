<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthInput from '@/components/auth/AuthInput.vue';
import AuthSubmit from '@/components/auth/AuthSubmit.vue';
import AuthStatus from '@/components/AuthStatus.vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { translate } from '@/lib/i18n';
import { store } from '@/routes/two-factor/login';

defineOptions({
    layout: () => ({
        title: translate('Two-factor authentication'),
        description: translate(
            'Confirm access to your account by entering the code from your authenticator application.',
        ),
        icon: 'lock',
        statement: {
            lead: translate('One more step'),
            accent: translate('and only you.'),
            body: translate(
                'A stolen password gets no further than this screen. Reach for your authenticator, or a recovery code if the phone is not to hand.',
            ),
        },
    }),
});

defineProps<{
    status?: string;
}>();

const useRecoveryCode = ref(false);

function toggleRecoveryCode(): void {
    useRecoveryCode.value = !useRecoveryCode.value;
}
</script>

<template>
    <Head :title="$t('Two-factor authentication')" />

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
        class="flex flex-col gap-4.5"
    >
        <AuthStatus v-if="status">{{ status }}</AuthStatus>

        <FormField
            v-if="!useRecoveryCode"
            id="code"
            :label="$t('Authentication code')"
            :error="errors.code"
            v-slot="{ id }"
        >
            <AuthInput
                :id="id"
                name="code"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                autofocus
                :placeholder="$t('123456')"
            />
        </FormField>

        <FormField
            v-else
            id="recovery_code"
            :label="$t('Recovery code')"
            :error="errors.recovery_code"
            v-slot="{ id }"
        >
            <AuthInput
                :id="id"
                name="recovery_code"
                type="text"
                autocomplete="one-time-code"
                autofocus
            />
        </FormField>

        <AuthSubmit
            class="mt-1"
            :loading="processing"
            data-test="two-factor-challenge-button"
        >
            {{ $t('Continue') }}
        </AuthSubmit>

        <div class="text-center text-sm">
            <Button
                type="button"
                variant="link"
                class="text-muted-foreground"
                data-test="toggle-recovery-code"
                @click="toggleRecoveryCode"
            >
                <template v-if="!useRecoveryCode">
                    {{ $t('Use a recovery code') }}
                </template>
                <template v-else>
                    {{ $t('Use an authentication code') }}
                </template>
            </Button>
        </div>
    </Form>
</template>
