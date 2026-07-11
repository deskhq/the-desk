<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import AuthStatus from '@/components/AuthStatus.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TeamInvitationAlert from '@/components/TeamInvitationAlert.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { translate } from '@/lib/i18n';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import type { TeamInvitationContext } from '@/types';

defineOptions({
    layout: {
        title: translate('Log in to your account'),
        description: translate('Enter your email and password below to log in'),
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
    teamInvitation?: TeamInvitationContext | null;
}>();
</script>

<template>
    <Head :title="$t('Log in')" />

    <div class="flex flex-col gap-5">
        <AuthStatus v-if="status">{{ status }}</AuthStatus>

        <TeamInvitationAlert
            v-if="teamInvitation"
            :invitation="teamInvitation"
            action="Log in"
        />

        <Form
            v-bind="store.form()"
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">{{ $t('Email address') }}</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">{{ $t('Password') }}</Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-sm"
                            :tabindex="5"
                        >
                            {{ $t('Forgot password?') }}
                        </TextLink>
                    </div>
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        :placeholder="$t('Password')"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" name="remember" :tabindex="3" />
                        <span>{{ $t('Remember me') }}</span>
                    </Label>
                </div>

                <Button
                    type="submit"
                    class="mt-4 w-full rounded-full"
                    :tabindex="4"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    {{ $t('Log in') }}
                </Button>
            </div>

            <div
                v-if="$page.props.registrationEnabled"
                class="text-center text-sm text-muted-foreground"
            >
                {{ $t("Don't have an account?") }}
                <TextLink
                    :href="
                        register({
                            query: {
                                invitation: teamInvitation?.code,
                            },
                        })
                    "
                    :tabindex="5"
                    data-test="register-link"
                >
                    {{ $t('Sign up') }}
                </TextLink>
            </div>
        </Form>
    </div>
</template>
