<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import InputError from '@/components/InputError.vue';
import SettingsSection from '@/components/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTimezone } from '@/composables/useTimezone';
import { translate } from '@/lib/i18n';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: translate('Profile settings'),
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const { timezone, setTimezone } = useTimezone();

// The full IANA zone list from the runtime, labelled with underscores spaced out
// (e.g. "America/New_York" → "America/New York") for readability.
const timezoneOptions = Intl.supportedValuesOf('timeZone').map((zone) => ({
    value: zone,
    label: zone.replace(/_/g, ' '),
}));

function onTimezoneSelect(value: unknown): void {
    if (typeof value === 'string') {
        setTimezone(value);
    }
}
</script>

<template>
    <Head :title="$t('Profile settings')" />

    <h1 class="sr-only">{{ $t('Profile settings') }}</h1>

    <SettingsSection
        :title="$t('Profile')"
        :description="$t('Update your name and email address')"
    >
        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <div class="grid gap-2">
                <Label for="name">{{ $t('Name') }}</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    :placeholder="$t('Full name')"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ $t('Email address') }}</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    :placeholder="$t('Email address')"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="pronouns">{{ $t('Pronouns') }}</Label>
                <Input
                    id="pronouns"
                    class="mt-1 block w-full"
                    name="pronouns"
                    :default-value="user.pronouns ?? ''"
                    maxlength="50"
                    :placeholder="$t('e.g. she/her, they/them')"
                />
                <InputError class="mt-2" :message="errors.pronouns" />
            </div>

            <div class="grid gap-2">
                <Label for="title">{{ $t('Job title') }}</Label>
                <Input
                    id="title"
                    class="mt-1 block w-full"
                    name="title"
                    :default-value="user.title ?? ''"
                    maxlength="100"
                    :placeholder="$t('e.g. Product Designer')"
                />
                <InputError class="mt-2" :message="errors.title" />
            </div>

            <div class="grid gap-2">
                <Label for="phone">{{ $t('Phone') }}</Label>
                <Input
                    id="phone"
                    type="tel"
                    class="mt-1 block w-full"
                    name="phone"
                    :default-value="user.phone ?? ''"
                    maxlength="30"
                    autocomplete="tel"
                    :placeholder="$t('e.g. +1 555 123 4567')"
                />
                <InputError class="mt-2" :message="errors.phone" />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    {{ $t('Your email address is unverified.') }}
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        {{
                            $t('Click here to re-send the verification email.')
                        }}
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    {{
                        $t(
                            'A new verification link has been sent to your email address.',
                        )
                    }}
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    class="rounded-full px-6"
                    data-test="update-profile-button"
                    >{{ $t('Save') }}</Button
                >
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
    </SettingsSection>

    <SettingsSection
        :title="$t('Timezone')"
        :description="
            $t(
                'Detected automatically on your first sign-in. It sets how timestamps read for you and the local time others see on your profile.',
            )
        "
    >
        <div class="grid gap-2">
            <Label for="timezone">{{ $t('Timezone') }}</Label>
            <Select
                :model-value="timezone ?? undefined"
                @update:model-value="onTimezoneSelect"
            >
                <SelectTrigger
                    id="timezone"
                    class="w-full"
                    data-test="timezone"
                >
                    <SelectValue :placeholder="$t('Select a timezone')" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in timezoneOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>
    </SettingsSection>

    <SettingsSection
        :title="$t('Danger zone')"
        :description="$t('Irreversible actions for your account.')"
    >
        <div
            class="rounded-lg border border-destructive/30 bg-destructive/5 p-4"
        >
            <DeleteUser />
        </div>
    </SettingsSection>
</template>
