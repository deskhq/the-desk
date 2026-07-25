<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Mail } from '@lucide/vue';
import { ref } from 'vue';
import AuthInput from '@/components/auth/AuthInput.vue';
import AuthSubmit from '@/components/auth/AuthSubmit.vue';
import AuthStatus from '@/components/AuthStatus.vue';
import FormField from '@/components/FormField.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { useResendCooldown } from '@/composables/useResendCooldown';
import { translate } from '@/lib/i18n';
import { login } from '@/routes';
import { email as requestResetLink } from '@/routes/password';

/** Long enough to stop a double press, short enough not to strand anyone. */
const COOLDOWN_SECONDS = 60;

defineOptions({
    layout: (props: { linkExpiresInMinutes: number }) => ({
        eyebrow: translate('Password reset'),
        title: translate('Forgot password'),
        statement: {
            lead: translate('It happens.'),
            accent: translate('Let’s fix it.'),
            body: translate(
                'We’ll email you a link that signs you in and lets you set a new password. The link works once and expires in :minutes minutes.',
                { minutes: props.linkExpiresInMinutes },
            ),
        },
        topAction: {
            label: translate('Back to log in'),
            href: login.url(),
            back: true,
            testId: 'back-to-login',
        },
    }),
});

defineProps<{
    status?: string;
    /** How long a reset link stays valid, so the copy never hardcodes it. */
    linkExpiresInMinutes: number;
}>();

const address = ref('');

/**
 * The address the last successful send went to. Held separately from the input
 * so editing the field afterwards cannot rewrite what the confirmation claims.
 */
const sentTo = ref('');

const {
    isCooling,
    formatted,
    start: startCooldown,
} = useResendCooldown(COOLDOWN_SECONDS);

function onSent(): void {
    sentTo.value = address.value;
    startCooldown();
}
</script>

<template>
    <Head :title="$t('Forgot password')" />

    <Form
        v-bind="requestResetLink.form()"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-4.5"
        @success="onSent"
    >
        <p class="text-[15px] leading-normal text-muted-foreground">
            {{
                $t(
                    'Enter the email you signed up with and we’ll send a reset link.',
                )
            }}
        </p>

        <!-- Naming the address needs the one we just submitted, which only this
        page knows. On a fresh load carrying a session status (no submit behind
        it) the plain confirmation stands in. -->
        <div
            v-if="status && sentTo"
            role="status"
            class="flex items-start gap-2.75 rounded-[11px] border border-emerald-600/30 bg-emerald-600/10 px-3.5 py-3"
            data-test="reset-link-sent"
        >
            <Mail class="mt-0.5 size-3.75 shrink-0 text-emerald-600" />
            <span class="text-[13.5px] leading-normal">
                {{
                    $t(
                        'We sent a reset link to :email. It expires in :minutes minutes.',
                        { email: sentTo, minutes: linkExpiresInMinutes },
                    )
                }}
            </span>
        </div>

        <AuthStatus v-else-if="status">{{ status }}</AuthStatus>

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
                autofocus
                v-model="address"
                placeholder="email@example.com"
            />
        </FormField>

        <!-- Once a link is out, the button becomes a quiet countdown rather than
        an invitation to press again. The server throttle is the real limit; this
        just stops the obvious double send. -->
        <Button
            v-if="isCooling"
            type="button"
            variant="outline"
            disabled
            class="h-12 w-full rounded-full text-[15px] font-medium"
            data-test="resend-cooldown"
        >
            {{ $t('Resend in :countdown', { countdown: formatted }) }}
        </Button>

        <AuthSubmit
            v-else
            :loading="processing"
            data-test="email-password-reset-link-button"
        >
            {{ $t('Email reset link') }}
        </AuthSubmit>

        <p class="text-center text-sm text-muted-foreground">
            {{ $t('Remembered it?') }}
            <TextLink :href="login()">{{ $t('Log in') }}</TextLink>
        </p>
    </Form>
</template>
