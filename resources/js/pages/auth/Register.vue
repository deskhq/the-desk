<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { ref } from 'vue';
import AuthInput from '@/components/auth/AuthInput.vue';
import AuthSubmit from '@/components/auth/AuthSubmit.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import PasswordStrengthMeter from '@/components/PasswordStrengthMeter.vue';
import TextLink from '@/components/TextLink.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { translate } from '@/lib/i18n';
import { login } from '@/routes';
import { store } from '@/routes/register';
import type { TeamInvitationContext } from '@/types';

const props = defineProps<{
    passwordRules: string;
    teamInvitation?: TeamInvitationContext | null;
    /** The operator's terms of service; null unless both legal links are configured. */
    termsUrl?: string | null;
    /** The operator's privacy notice; null unless both legal links are configured. */
    privacyUrl?: string | null;
}>();

defineOptions({
    layout: () => ({
        eyebrow: translate('Your account'),
        title: translate('Create account'),
        mirrored: true,
        statement: {
            lead: translate('Somewhere to put'),
            accent: translate('the conversation.'),
            body: translate(
                'Open source, self-hosted team chat. Create an account and your first workspace comes with it.',
            ),
        },
    }),
});

/** Scored live for the meter; the server's rules remain the only real gate. */
const password = ref('');

/** Present only under an invitation, where the address is settled and enforced. */
const invitedEmail = ref(props.teamInvitation?.email ?? '');
</script>

<template>
    <Head :title="$t('Register')" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-4.5"
    >
        <input
            v-if="teamInvitation"
            type="hidden"
            name="invitation"
            :value="teamInvitation.code"
        />

        <FormField
            id="name"
            :label="$t('Name')"
            :error="errors.name"
            v-slot="{ id }"
        >
            <AuthInput
                :id="id"
                type="text"
                required
                autofocus
                :tabindex="1"
                autocomplete="name"
                name="name"
                :placeholder="$t('Full name')"
            />
        </FormField>

        <FormField
            id="email"
            :label="$t('Email address')"
            :error="errors.email"
            v-slot="{ id }"
        >
            <AuthInput
                v-if="teamInvitation"
                :id="id"
                type="email"
                required
                :tabindex="2"
                autocomplete="email"
                name="email"
                locked
                v-model="invitedEmail"
                data-test="invited-email"
            >
                <template #badge>
                    <span
                        class="inline-flex items-center gap-1.5 text-[11px] font-bold tracking-[0.06em] text-emerald-700 uppercase dark:text-emerald-400"
                    >
                        <Check class="size-3" />
                        {{ $t('invited') }}
                    </span>
                </template>
            </AuthInput>

            <AuthInput
                v-else
                :id="id"
                type="email"
                required
                :tabindex="2"
                autocomplete="email"
                name="email"
                placeholder="email@example.com"
            />
        </FormField>

        <div class="grid gap-3.5 sm:grid-cols-2">
            <FormField
                id="password"
                :label="$t('Password')"
                :error="errors.password"
                v-slot="{ id }"
            >
                <PasswordInput
                    :id="id"
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    name="password"
                    v-model="password"
                    class="h-12 rounded-[10px] px-4.5 text-base shadow-none md:text-base"
                    :placeholder="$t('Password')"
                    :passwordrules="passwordRules"
                />
            </FormField>

            <FormField
                id="password_confirmation"
                :label="$t('Confirm')"
                :error="errors.password_confirmation"
                v-slot="{ id }"
            >
                <PasswordInput
                    :id="id"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    name="password_confirmation"
                    class="h-12 rounded-[10px] px-4.5 text-base shadow-none md:text-base"
                    :placeholder="$t('Confirm password')"
                    :passwordrules="passwordRules"
                />
            </FormField>
        </div>

        <PasswordStrengthMeter :password="password" />

        <div v-if="termsUrl && privacyUrl" class="grid gap-2">
            <Label
                for="terms"
                class="items-start gap-2.75 text-[13.5px] leading-normal font-normal"
            >
                <Checkbox
                    id="terms"
                    name="terms"
                    :tabindex="5"
                    class="mt-0.5 size-5 rounded-[6px] data-[state=checked]:text-brass"
                    data-test="terms-checkbox"
                />
                <span>
                    {{ $t('I agree to the') }}
                    <a
                        :href="termsUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-brass-fill-foreground underline decoration-brass-border/40 underline-offset-4 hover:decoration-brass-border"
                        >{{ $t('terms') }}</a
                    >
                    {{ $t('and') }}
                    <a
                        :href="privacyUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-brass-fill-foreground underline decoration-brass-border/40 underline-offset-4 hover:decoration-brass-border"
                        >{{ $t('privacy notice') }}</a
                    >.
                </span>
            </Label>
            <InputError :message="errors.terms" />
        </div>

        <AuthSubmit
            class="mt-1"
            :tabindex="6"
            :loading="processing"
            data-test="register-user-button"
        >
            {{ $t('Create account') }}
        </AuthSubmit>

        <p class="text-center text-sm text-muted-foreground">
            {{ $t('Already have an account?') }}
            <TextLink
                :href="
                    teamInvitation
                        ? login.url({
                              query: {
                                  invitation: teamInvitation.code,
                              },
                          })
                        : login()
                "
                :tabindex="7"
                data-test="team-invitation-login-link"
            >
                {{ $t('Log in') }}
            </TextLink>
        </p>
    </Form>
</template>
