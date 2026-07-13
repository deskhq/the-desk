<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { translate } from '@/lib/i18n';
import { store } from '@/routes/password/confirm';

defineOptions({
    layout: {
        title: translate('Confirm password'),
        description: translate(
            'This is a secure area of the application. Please confirm your password before continuing.',
        ),
        icon: 'lock',
    },
});
</script>

<template>
    <Head :title="$t('Confirm password')" />

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
    >
        <div class="space-y-6">
            <div class="grid gap-2">
                <Label for="password">{{ $t('Password') }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                    autofocus
                />

                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center">
                <Button
                    class="w-full rounded-full"
                    :disabled="processing"
                    data-test="confirm-password-button"
                >
                    <Spinner v-if="processing" />
                    {{ $t('Confirm password') }}
                </Button>
            </div>
        </div>
    </Form>
</template>
