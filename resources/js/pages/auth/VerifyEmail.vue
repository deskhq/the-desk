<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import AuthStatus from '@/components/AuthStatus.vue';
import { Button } from '@/components/ui/button';
import { translate } from '@/lib/i18n';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: () => ({
        title: translate('Check your email'),
        description: translate(
            'Please verify your email address by clicking on the link we just emailed to you.',
        ),
        icon: 'mail',
        statement: {
            lead: translate('One click'),
            accent: translate('and you’re in.'),
            body: translate(
                'This workspace keeps itself to verified addresses. Confirm yours and your channels are waiting on the other side.',
            ),
        },
    }),
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head :title="$t('Email verification')" />

    <Form v-bind="send.form()" class="flex flex-col" v-slot="{ processing }">
        <AuthStatus v-if="status === 'verification-link-sent'">
            {{
                $t(
                    'A new verification link has been sent to the email address you provided during registration.',
                )
            }}
        </AuthStatus>

        <Button
            :loading="processing"
            variant="outline"
            class="mt-5.5 h-13 w-full rounded-full bg-muted text-[15px] font-medium hover:bg-accent"
        >
            {{ $t('Resend verification email') }}
        </Button>

        <Link
            :href="logout()"
            as="button"
            class="mt-4 self-center rounded-sm text-sm text-muted-foreground underline decoration-input underline-offset-4 transition-colors hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-hidden"
        >
            {{ $t('Log out') }}
        </Link>
    </Form>
</template>
