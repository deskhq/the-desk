<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import AuthStatus from '@/components/AuthStatus.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Check your email',
        description:
            'Please verify your email address by clicking on the link we just emailed to you.',
        icon: 'mail',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Email verification" />

    <AuthStatus v-if="status === 'verification-link-sent'" class="mb-5">
        A new verification link has been sent to the email address you provided
        during registration.
    </AuthStatus>

    <Form
        v-bind="send.form()"
        class="flex flex-col items-center gap-4"
        v-slot="{ processing }"
    >
        <Button
            :disabled="processing"
            variant="outline"
            class="w-full rounded-full bg-muted hover:bg-accent"
        >
            <Spinner v-if="processing" />
            Resend verification email
        </Button>

        <Link
            :href="logout()"
            as="button"
            class="text-sm text-muted-foreground underline decoration-input underline-offset-4 transition-colors hover:text-foreground"
        >
            Log out
        </Link>
    </Form>
</template>
