<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/InputError.vue';
import LogOutOtherDevicesDialog from '@/components/LogOutOtherDevicesDialog.vue';
import ManageSessions from '@/components/ManageSessions.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import SecurityActivity from '@/components/SecurityActivity.vue';
import SettingsPane from '@/components/SettingsPane.vue';
import SettingsPaneSection from '@/components/SettingsPaneSection.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { translate } from '@/lib/i18n';
import { edit } from '@/routes/security';
import type { ActiveSession, SecurityActivityEvent } from '@/types';

type Props = {
    passwordRules: string;
    sessions: ActiveSession[];
    securityEvents: SecurityActivityEvent[];
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
                <div class="grid gap-2">
                    <Label for="current_password">{{
                        $t('Current password')
                    }}</Label>
                    <PasswordInput
                        id="current_password"
                        name="current_password"
                        class="mt-1 block w-full"
                        autocomplete="current-password"
                        :placeholder="$t('Current password')"
                    />
                    <InputError :message="errors.current_password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">{{ $t('New password') }}</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                        :placeholder="$t('New password')"
                        :passwordrules="props.passwordRules"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">{{
                        $t('Confirm password')
                    }}</Label>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                        :placeholder="$t('Confirm password')"
                        :passwordrules="props.passwordRules"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>

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
