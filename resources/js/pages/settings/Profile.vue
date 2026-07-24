<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DemoLock from '@/components/DemoLock.vue';
import FormField from '@/components/FormField.vue';
import AvatarUpload from '@/components/settings/AvatarUpload.vue';
import SettingsSection from '@/components/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    layout: () => ({
        breadcrumbs: [
            {
                title: translate('Profile settings'),
                href: edit(),
            },
        ],
    }),
});

const page = usePage();
const user = computed(() => page.props.auth.user);

// Whether the avatar is an uploaded blob (so "Remove photo" applies) rather
// than a derived Gravatar/initials fallback.
const hasCustomAvatar = computed(() => Boolean(page.props.hasCustomAvatar));

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
        :title="$t('Photo')"
        :description="
            $t(
                'JPEG, PNG or WebP, up to 5 MB. Shown in a circle, so square images work best.',
            )
        "
    >
        <AvatarUpload
            :avatar="user.avatar ?? null"
            :name="user.name"
            :has-custom-avatar="hasCustomAvatar"
        />
    </SettingsSection>

    <SettingsSection
        :title="$t('Profile')"
        :description="$t('Update your name and email address')"
    >
        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <FormField
                id="name"
                :label="$t('Name')"
                :error="errors.name"
                v-slot="{ id }"
            >
                <Input
                    :id="id"
                    class="max-md:h-11"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    :placeholder="$t('Full name')"
                />
            </FormField>

            <FormField
                id="email"
                :label="$t('Email address')"
                :error="errors.email"
                v-slot="{ id }"
            >
                <Input
                    :id="id"
                    class="max-md:h-11"
                    type="email"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    :placeholder="$t('Email address')"
                />
            </FormField>

            <FormField
                id="pronouns"
                :label="$t('Pronouns')"
                :error="errors.pronouns"
                v-slot="{ id }"
            >
                <Input
                    :id="id"
                    class="max-md:h-11"
                    name="pronouns"
                    :default-value="user.pronouns ?? ''"
                    maxlength="50"
                    :placeholder="$t('e.g. she/her, they/them')"
                />
            </FormField>

            <FormField
                id="title"
                :label="$t('Job title')"
                :error="errors.title"
                v-slot="{ id }"
            >
                <Input
                    :id="id"
                    class="max-md:h-11"
                    name="title"
                    :default-value="user.title ?? ''"
                    maxlength="100"
                    :placeholder="$t('e.g. Product Designer')"
                />
            </FormField>

            <FormField
                id="phone"
                :label="$t('Phone')"
                :error="errors.phone"
                v-slot="{ id }"
            >
                <Input
                    :id="id"
                    class="max-md:h-11"
                    type="tel"
                    name="phone"
                    :default-value="user.phone ?? ''"
                    maxlength="30"
                    autocomplete="tel"
                    :placeholder="$t('e.g. +1 555 123 4567')"
                />
            </FormField>

            <div
                v-if="
                    page.props.emailVerificationEnabled &&
                    !user.email_verified_at
                "
            >
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
                <DemoLock v-slot="{ disabled }">
                    <Button
                        :disabled="processing || disabled"
                        class="rounded-full px-6 max-md:h-11"
                        data-test="update-profile-button"
                        >{{ $t('Save') }}</Button
                    >
                </DemoLock>
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
        <FormField id="timezone" :label="$t('Timezone')" v-slot="{ id }">
            <Select
                :model-value="timezone ?? undefined"
                @update:model-value="onTimezoneSelect"
            >
                <SelectTrigger
                    :id="id"
                    class="w-full max-md:data-[size=default]:h-11"
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
        </FormField>
    </SettingsSection>
</template>
