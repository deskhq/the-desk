<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import FormField from '@/components/FormField.vue';
import LogOutOtherDevicesDialog from '@/components/LogOutOtherDevicesDialog.vue';
import ManageSessions from '@/components/ManageSessions.vue';
import PasskeyManagement from '@/components/PasskeyManagement.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import SecurityActivity from '@/components/SecurityActivity.vue';
import SettingsPane from '@/components/SettingsPane.vue';
import SettingsPaneSection from '@/components/SettingsPaneSection.vue';
import TwoFactorAuthentication from '@/components/TwoFactorAuthentication.vue';
import { Button } from '@/components/ui/button';
import { translate } from '@/lib/i18n';
import { edit } from '@/routes/security';
import type { ActiveSession, SecurityActivityEvent } from '@/types';

type Props = {
    passwordRules: string;
    sessions: ActiveSession[];
    securityEvents: SecurityActivityEvent[];
    canManageTwoFactor: boolean;
    twoFactor?: App.Data.TwoFactorStateData | null;
    canManagePasskeys: boolean;
    passkeys?: App.Data.PasskeyData[];
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: translate('Security settings'),
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="$t('Security settings')" />

    <h1 class="sr-only">{{ $t('Security settings') }}</h1>

    <SettingsPane
        :title="$t('Security')"
        :description="
            $t('Where you\'re signed in, and what\'s happened on your account')
        "
    >
        <SettingsPaneSection
            :title="$t('Update password')"
            :description="
                $t(
                    'Ensure your account is using a long, random password to stay secure',
                )
            "
        >
            <Form
                v-bind="SecurityController.update.form()"
                :options="{
                    preserveScroll: true,
                }"
                reset-on-success
                :reset-on-error="[
                    'password',
                    'password_confirmation',
                    'current_password',
                ]"
                class="space-y-6"
                v-slot="{ errors, processing, recentlySuccessful }"
            >
                <FormField
                    id="current_password"
                    :label="$t('Current password')"
                    :error="errors.current_password"
                    v-slot="{ id }"
                >
                    <PasswordInput
                        :id="id"
                        name="current_password"
                        autocomplete="current-password"
                        :placeholder="$t('Current password')"
                    />
                </FormField>

                <FormField
                    id="password"
                    :label="$t('New password')"
                    :error="errors.password"
                    v-slot="{ id }"
                >
                    <PasswordInput
                        :id="id"
                        name="password"
                        autocomplete="new-password"
                        :placeholder="$t('New password')"
                        :passwordrules="props.passwordRules"
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
                        autocomplete="new-password"
                        :placeholder="$t('Confirm password')"
                        :passwordrules="props.passwordRules"
                    />
                </FormField>

                <div class="flex items-center gap-4">
                    <Button
                        :disabled="processing"
                        class="rounded-full px-6"
                        data-test="update-password-button"
                    >
                        {{ $t('Save') }}
                    </Button>
                    <Transition
                        enter-active-class="transition ease-in-out"
                        enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out"
                        leave-to-class="opacity-0"
                    >
                        <p
                            v-show="recentlySuccessful"
                            class="font-serif text-sm text-muted-foreground italic"
                        >
                            {{ $t('Saved just now') }}
                        </p>
                    </Transition>
                </div>
            </Form>
        </SettingsPaneSection>

        <SettingsPaneSection
            v-if="props.canManageTwoFactor"
            :title="$t('Two-factor authentication')"
            :description="
                $t(
                    'Add a one-time code from an authenticator app to your sign-in',
                )
            "
        >
            <TwoFactorAuthentication :state="props.twoFactor ?? null" />
        </SettingsPaneSection>

        <SettingsPaneSection
            v-if="props.canManagePasskeys"
            :title="$t('Passkeys')"
            :description="
                $t(
                    'Sign in without a password using Touch ID, Face ID, or a security key',
                )
            "
        >
            <PasskeyManagement :passkeys="props.passkeys ?? []" />
        </SettingsPaneSection>

        <SettingsPaneSection
            :title="$t('Active sessions')"
            :count="props.sessions.length"
            :description="
                $t('Revoking a session signs that device out immediately')
            "
        >
            <template #action>
                <LogOutOtherDevicesDialog :sessions="props.sessions" />
            </template>

            <ManageSessions :sessions="props.sessions" />
        </SettingsPaneSection>

        <SettingsPaneSection
            :title="$t('Recent activity')"
            :description="
                $t(
                    'Sign-ins, password changes, and new devices on your account',
                )
            "
        >
            <SecurityActivity :events="props.securityEvents" />
        </SettingsPaneSection>
    </SettingsPane>
</template>
