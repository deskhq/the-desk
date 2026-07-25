<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { useDemoMode } from '@/composables/useDemoMode';
import { login as demoLogin } from '@/routes/demo';

/**
 * The public demo's one-click way in. Posting here signs the visitor into the
 * shared demo account and lands them in the seeded workspace, so a stranger
 * never has to know — or type — the credentials on the login screen.
 *
 * It renders nothing off the demo, so a real deployment never shows a dead CTA.
 * That is affordance only: the route 404s off the demo regardless, which is the
 * actual gate (see DemoLoginController).
 */
// Fallthrough attributes land on the button rather than the wrapping form, so a
// caller styles the control itself — the form is only there to carry the POST.
defineOptions({ inheritAttrs: false });

const { demoMode } = useDemoMode();
</script>

<template>
    <Form v-if="demoMode" v-bind="demoLogin.form()" v-slot="{ processing }">
        <Button
            v-bind="$attrs"
            type="submit"
            :loading="processing"
            data-test="demo-enter-button"
        >
            {{ $t('Enter the demo') }}
        </Button>
    </Form>
</template>
