<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import AuthStatus from '@/components/AuthStatus.vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { translate } from '@/lib/i18n';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: translate('Forgot password'),
        description: translate(
            'Enter your email to receive a password reset link',
        ),
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head :title="$t('Forgot password')" />

    <AuthStatus v-if="status" class="mb-6">{{ status }}</AuthStatus>

    <div class="space-y-6">
        <Form v-bind="email.form()" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="email">{{ $t('Email address') }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="off"
                    autofocus
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button
                    class="w-full rounded-full"
                    :disabled="processing"
                    data-test="email-password-reset-link-button"
                >
                    <Spinner v-if="processing" />
                    {{ $t('Email password reset link') }}
                </Button>
            </div>
        </Form>

        <div class="space-x-1 text-center text-sm text-muted-foreground">
            <span>{{ $t('Or, return to') }}</span>
            <TextLink :href="login()">{{ $t('log in') }}</TextLink>
        </div>
    </div>
</template>
