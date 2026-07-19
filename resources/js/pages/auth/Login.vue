<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import AuthStatus from '@/components/AuthStatus.vue';
import FormField from '@/components/FormField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TeamInvitationAlert from '@/components/TeamInvitationAlert.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { translate } from '@/lib/i18n';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { login as passkeyLogin, loginOptions } from '@/routes/passkey';
import { request } from '@/routes/password';
import { redirect as oidcRedirect } from '@/routes/sso/oidc';
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
    canLoginWithPasskey?: boolean;
    /**
     * The shared sign-in credentials for the public demo, advertised beneath the
     * login button so visitors can get in. Null off the demo.
     */
    demoCredentials?: { email: string; password: string } | null;
}>();

// Passwordless sign-in: run the WebAuthn assertion against the Fortify passkey
// endpoints, then follow the server's intended-URL redirect. The whole session
// changes on success, so a full navigation (not an Inertia visit) is safest.
const {
    verify: signInWithPasskey,
    isLoading: passkeyLoading,
    error: passkeyError,
    isSupported: passkeySupported,
} = usePasskeyVerify({
    routes: {
        options: loginOptions().url,
        submit: passkeyLogin().url,
    },
    onSuccess: (response) => {
        window.location.href = response.redirect ?? '/';
    },
});
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

        <!-- Alternative sign-in methods (single sign-on and/or passwordless
        passkey), each shown only when its operator toggle is on. They share a
        single "or continue with email" divider before the password form so it
        never renders twice. -->
        <div
            v-if="
                $page.props.sso.oidcEnabled ||
                (canLoginWithPasskey && passkeySupported)
            "
            class="flex flex-col gap-6"
        >
            <div class="flex flex-col gap-3">
                <!-- SSO: a full-page navigation (native anchor) hands off to the
                IdP; an Inertia visit would break the OAuth redirect. -->
                <Button
                    v-if="$page.props.sso.oidcEnabled"
                    as-child
                    variant="outline"
                    class="w-full rounded-full"
                    data-test="sso-login-button"
                >
                    <a :href="oidcRedirect.url()">
                        {{ $t('Sign in with SSO') }}
                    </a>
                </Button>

                <!-- Passwordless passkey sign-in. Falls away silently on
                browsers without WebAuthn support. -->
                <Button
                    v-if="canLoginWithPasskey && passkeySupported"
                    type="button"
                    variant="outline"
                    class="w-full rounded-full"
                    :loading="passkeyLoading"
                    data-test="passkey-login-button"
                    @click="signInWithPasskey"
                >
                    {{ $t('Sign in with a passkey') }}
                </Button>

                <p
                    v-if="passkeyError"
                    role="alert"
                    class="text-center text-sm text-destructive"
                    data-test="passkey-login-error"
                >
                    {{ passkeyError }}
                </p>
            </div>

            <div
                v-if="$page.props.sso.passwordLoginEnabled"
                class="flex items-center gap-3 text-xs text-muted-foreground uppercase"
            >
                <Separator class="flex-1" />
                <span>{{ $t('Or continue with email') }}</span>
                <Separator class="flex-1" />
            </div>
        </div>

        <Form
            v-if="$page.props.sso.passwordLoginEnabled"
            v-bind="store.form()"
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-6">
                <FormField
                    id="email"
                    :label="$t('Email address')"
                    :error="errors.email"
                    v-slot="{ id }"
                >
                    <Input
                        :id="id"
                        type="email"
                        name="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="email@example.com"
                    />
                </FormField>

                <FormField
                    id="password"
                    :label="$t('Password')"
                    :error="errors.password"
                >
                    <template #labelAction>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-sm"
                            :tabindex="5"
                        >
                            {{ $t('Forgot password?') }}
                        </TextLink>
                    </template>
                    <template #default="{ id }">
                        <PasswordInput
                            :id="id"
                            name="password"
                            required
                            :tabindex="2"
                            autocomplete="current-password"
                            :placeholder="$t('Password')"
                        />
                    </template>
                </FormField>

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
                    :loading="processing"
                    data-test="login-button"
                >
                    {{ $t('Log in') }}
                </Button>

                <div
                    v-if="demoCredentials"
                    data-test="demo-credentials"
                    class="rounded-xl border border-demo-banner-border bg-demo-banner px-4 py-3 text-center text-sm text-demo-banner-foreground"
                >
                    <p class="font-semibold text-demo-banner-strong">
                        {{ $t('Sign in with the shared demo account') }}
                    </p>
                    <dl class="mt-2 flex flex-col gap-1">
                        <div class="flex items-center justify-center gap-2">
                            <dt class="text-demo-banner-foreground/80">
                                {{ $t('Email') }}
                            </dt>
                            <dd>
                                <code
                                    class="rounded bg-demo-chip px-1.5 py-0.5 font-mono text-demo-chip-foreground"
                                    >{{ demoCredentials.email }}</code
                                >
                            </dd>
                        </div>
                        <div class="flex items-center justify-center gap-2">
                            <dt class="text-demo-banner-foreground/80">
                                {{ $t('Password') }}
                            </dt>
                            <dd>
                                <code
                                    class="rounded bg-demo-chip px-1.5 py-0.5 font-mono text-demo-chip-foreground"
                                    >{{ demoCredentials.password }}</code
                                >
                            </dd>
                        </div>
                    </dl>
                </div>
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
