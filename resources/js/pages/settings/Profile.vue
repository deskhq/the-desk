<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DataPrivacy from '@/components/DataPrivacy.vue';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { DataExport } from '@/types';

type Props = {
    dataExport: DataExport | null;
};

defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
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
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Update your name and email address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="pronouns">Pronouns</Label>
                <Input
                    id="pronouns"
                    class="mt-1 block w-full"
                    name="pronouns"
                    :default-value="user.pronouns ?? ''"
                    maxlength="50"
                    placeholder="e.g. she/her, they/them"
                />
                <InputError class="mt-2" :message="errors.pronouns" />
            </div>

            <div class="grid gap-2">
                <Label for="title">Job title</Label>
                <Input
                    id="title"
                    class="mt-1 block w-full"
                    name="title"
                    :default-value="user.title ?? ''"
                    maxlength="100"
                    placeholder="e.g. Product Designer"
                />
                <InputError class="mt-2" :message="errors.title" />
            </div>

            <div class="grid gap-2">
                <Label for="phone">Phone</Label>
                <Input
                    id="phone"
                    type="tel"
                    class="mt-1 block w-full"
                    name="phone"
                    :default-value="user.phone ?? ''"
                    maxlength="30"
                    autocomplete="tel"
                    placeholder="e.g. +1 555 123 4567"
                />
                <InputError class="mt-2" :message="errors.phone" />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    Your email address is unverified.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>

        <div class="grid max-w-md gap-2">
            <Label for="timezone">Timezone</Label>
            <Select
                :model-value="timezone ?? undefined"
                @update:model-value="onTimezoneSelect"
            >
                <SelectTrigger
                    id="timezone"
                    class="w-full"
                    data-test="timezone"
                >
                    <SelectValue placeholder="Select a timezone" />
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
            <p class="text-sm text-muted-foreground">
                Detected automatically on your first sign-in. It sets how
                timestamps read for you and the local time others see on your
                profile.
            </p>
        </div>
    </div>

    <DataPrivacy :data-export="dataExport" />

    <DeleteUser />
</template>
