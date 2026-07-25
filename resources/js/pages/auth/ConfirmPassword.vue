<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import AuthSubmit from '@/components/auth/AuthSubmit.vue';
import FormField from '@/components/FormField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { translate } from '@/lib/i18n';
import { store } from '@/routes/password/confirm';

defineOptions({
    layout: () => ({
        title: translate('Confirm password'),
        description: translate(
            'This is a secure area of the application. Please confirm your password before continuing.',
        ),
        icon: 'lock',
        statement: {
            lead: translate('Just checking'),
            accent: translate('it’s still you.'),
            body: translate(
                'Some changes reach far enough that a live session is not proof enough on its own. Your password is.',
            ),
        },
    }),
});
</script>

<template>
    <Head :title="$t('Confirm password')" />

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
        class="flex flex-col gap-4.5"
    >
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
                autocomplete="current-password"
                autofocus
                class="h-12 rounded-[10px] px-4.5 text-base shadow-none md:text-base"
                :placeholder="$t('Password')"
            />
        </FormField>

        <AuthSubmit
            class="mt-1"
            :loading="processing"
            data-test="confirm-password-button"
        >
            {{ $t('Confirm password') }}
        </AuthSubmit>
    </Form>
</template>
