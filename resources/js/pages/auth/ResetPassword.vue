<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthInput from '@/components/auth/AuthInput.vue';
import AuthSubmit from '@/components/auth/AuthSubmit.vue';
import FormField from '@/components/FormField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import PasswordStrengthMeter from '@/components/PasswordStrengthMeter.vue';
import { Button } from '@/components/ui/button';
import { translate } from '@/lib/i18n';
import { request, update } from '@/routes/password';

defineOptions({
    layout: (props: { linkExpiresInMinutes: number }) => ({
        eyebrow: translate('Password reset'),
        title: translate('Reset password'),
        statement: {
            lead: translate('One link,'),
            accent: translate('one new password.'),
            body: translate(
                'Choose something you have not used elsewhere. The link that brought you here works once and expires in :minutes minutes.',
                { minutes: props.linkExpiresInMinutes },
            ),
        },
    }),
});

const props = defineProps<{
    token: string;
    email: string;
    passwordRules: string;
    /** How long a reset link stays valid, so the copy never hardcodes it. */
    linkExpiresInMinutes: number;
}>();

const inputEmail = ref(props.email);
const password = ref('');
</script>

<template>
    <Head :title="$t('Reset password')" />

    <Form
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-4.5"
    >
        <FormField
            id="email"
            :label="$t('Email address')"
            :error="errors.email"
            v-slot="{ id }"
        >
            <AuthInput
                :id="id"
                type="email"
                name="email"
                autocomplete="email"
                v-model="inputEmail"
                locked
            />
        </FormField>

        <FormField
            id="password"
            :label="$t('Password')"
            :error="errors.password"
            v-slot="{ id }"
        >
            <PasswordInput
                :id="id"
                name="password"
                required
                autocomplete="new-password"
                autofocus
                v-model="password"
                class="h-12 rounded-[10px] px-4.5 text-base shadow-none md:text-base"
                :placeholder="$t('Password')"
                :passwordrules="passwordRules"
            />
        </FormField>

        <FormField
            id="password_confirmation"
            :label="$t('Confirm password')"
            :error="errors.password_confirmation"
            v-slot="{ id }"
        >
            <PasswordInput
                :id="id"
                name="password_confirmation"
                required
                autocomplete="new-password"
                class="h-12 rounded-[10px] px-4.5 text-base shadow-none md:text-base"
                :placeholder="$t('Confirm password')"
                :passwordrules="passwordRules"
            />
        </FormField>

        <PasswordStrengthMeter :password="password" />

        <AuthSubmit
            class="mt-1"
            :loading="processing"
            data-test="reset-password-button"
        >
            {{ $t('Reset password') }}
        </AuthSubmit>

        <!-- A dead token surfaces as an error on the address field, and there is
        exactly one thing to do about it, so the way out sits right beneath. -->
        <Button
            v-if="errors.email"
            as-child
            variant="outline"
            class="h-12 w-full rounded-full text-[15px] font-medium"
            data-test="request-new-reset-link"
        >
            <Link :href="request()">{{ $t('Send a new link') }}</Link>
        </Button>
    </Form>
</template>
