<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The project the instance runs on. Deliberately not `APP_NAME`: a whitelabeled
 * instance is called whatever its operator called it, and this line credits the
 * software rather than the deployment.
 */
const PROJECT_URL = 'https://thedeskhq.app';

const page = usePage();

/**
 * The licence is MIT with no trademark clause, so this is a request, not a
 * requirement — BRANDING_ATTRIBUTION=false removes the line outright rather
 * than leaving an operator to patch this component out.
 */
const attribution = computed(() => page.props.branding.attribution);
</script>

<template>
    <a
        v-if="attribution"
        :href="PROJECT_URL"
        target="_blank"
        rel="noopener noreferrer"
        data-test="powered-by"
        class="text-muted-foreground hover:text-foreground"
    >
        {{ $t('Powered by The Desk') }}
    </a>
</template>
