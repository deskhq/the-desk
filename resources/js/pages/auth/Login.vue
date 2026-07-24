<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import { Lock } from '@lucide/vue';
import AuthInput from '@/components/auth/AuthInput.vue';
import AuthSubmit from '@/components/auth/AuthSubmit.vue';
import AuthStatus from '@/components/AuthStatus.vue';
import FormField from '@/components/FormField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TeamInvitationAlert from '@/components/TeamInvitationAlert.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    layout: (props: {
        registrationEnabled: boolean;
        teamInvitation?: TeamInvitationContext | null;
    }) => ({
        eyebrow: translate('Welcome back'),
        title: translate('Log in'),
        statement: {
            lead: translate('Where the work actually'),
            accent: translate('gets said.'),
            body: translate(
                'Open source, self-hosted team chat. Your channels, your threads, your server — no seat counting, no data leaving the building.',
            ),
        },
        topAction: props.registrationEnabled
            ? {
                  prefix: translate('New here?'),
                  label: translate('Create account'),
                  href: register.url({
                      query: { invitation: props.teamInvitation?.code },
                  }),
                  testId: 'register-link',
              }
            : undefined,
    }),
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

    <div class="flex flex-col gap-4">
        <AuthStatus v-if="status">{{ status }}</AuthStatus>

        <TeamInvitationAlert
            v-if="teamInvitation"
            :invitation="teamInvitation"
            action="Log in"
        />

        <!-- Passkey sign-in leads: for anyone who has enrolled one it is both
        the fastest and the least phishable way in. Falls away silently on
        browsers without WebAuthn support. -->
        <template v-if="canLoginWithPasskey && passkeySupported">
            <AuthSubmit
                type="button"
                :loading="passkeyLoading"
                data-test="passkey-login-button"
                @click="signInWithPasskey"
            >
                {{ $t('Sign in with a passkey') }}
            </AuthSubmit>

            <p
                v-if="passkeyError"
                role="alert"
                class="rounded-[11px] border border-destructive/25 bg-destructive/10 px-3.5 py-3 text-sm text-destructive-text"
                data-test="passkey-login-error"
            >
                {{ passkeyError }}
            </p>

            <div
                v-if="$page.props.sso.passwordLoginEnabled"
                class="flex items-center gap-3.5 text-[12.5px] text-muted-foreground"
            >
                <Separator class="flex-1" />
                <span>{{ $t('or use your password') }}</span>
                <Separator class="flex-1" />
            </div>
        </template>

        <Form
            v-if="$page.props.sso.passwordLoginEnabled"
            v-bind="store.form()"
            :reset-on-success="['password']"
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
                        class="text-xs"
                        :tabindex="5"
                    >
                        {{ $t('Forgot?') }}
                    </TextLink>
                </template>
                <template #default="{ id }">
                    <PasswordInput
                        :id="id"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        class="h-12 rounded-[10px] px-4.5 text-base shadow-none md:text-base"
                        :placeholder="$t('Password')"
                    />
                </template>
            </FormField>

            <Label for="remember" class="flex items-center gap-2.5 text-sm">
                <Checkbox
                    id="remember"
                    name="remember"
                    :tabindex="3"
                    class="size-5 rounded-[6px] data-[state=checked]:text-brass"
                />
                <span>{{ $t('Keep me signed in') }}</span>
            </Label>

            <AuthSubmit
                class="mt-1.5"
                :tabindex="4"
                :loading="processing"
                data-test="login-button"
            >
                {{ $t('Log in') }}
            </AuthSubmit>

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
        </Form>

        <!-- Single sign-on sits below the password form as the secondary route
        in. A full-page navigation (native anchor) hands off to the IdP; an
        Inertia visit would break the OAuth redirect. -->
        <template v-if="$page.props.sso.oidcEnabled">
            <div
                v-if="$page.props.sso.passwordLoginEnabled"
                class="flex items-center gap-3.5 text-[13px] font-medium text-muted-foreground"
            >
                <Separator class="flex-1" />
                <span>{{ $t('or') }}</span>
                <Separator class="flex-1" />
            </div>

            <Button
                as-child
                variant="outline"
                class="h-13 w-full rounded-full text-[15px] font-medium"
                data-test="sso-login-button"
            >
                <a :href="oidcRedirect.url()">
                    <Lock class="text-muted-foreground" aria-hidden="true" />
                    {{ $t('Continue with SSO') }}
                </a>
            </Button>
        </template>

        <!-- The desktop shell puts this in the paper column's top row; on the
        stacked mobile layout there is no such row, so it closes the form. -->
        <p
            v-if="$page.props.registrationEnabled"
            class="text-center text-sm text-muted-foreground lg:hidden"
        >
            {{ $t('New here?') }}
            <TextLink
                :href="
                    register({
                        query: {
                            invitation: teamInvitation?.code,
                        },
                    })
                "
                :tabindex="5"
                data-test="register-link-mobile"
            >
                {{ $t('Create account') }}
            </TextLink>
        </p>
    </div>
</template>
