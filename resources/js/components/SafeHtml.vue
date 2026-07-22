<script setup lang="ts">
import { computed } from 'vue';
import { SANITIZE_CONFIGS, sanitizeHtml } from '@/lib/sanitizeHtml';
import type { SanitizeVariant } from '@/lib/sanitizeHtml';

/**
 * The single `v-html` surface in the app. Every run of HTML the client renders
 * as markup — message bodies, search snippets, the two-factor QR code — goes
 * through here, so the DOMPurify trust boundary is one component rather than a
 * convention each call site has to remember. `vue/no-v-html` is an error
 * everywhere except this file (see `eslint.config.js`), which is what keeps a
 * new raw `v-html` from slipping in.
 */
const props = withDefaults(
    defineProps<{
        /** The HTML to render. Assumed hostile; the allowlist is what makes it safe. */
        html: string;
        /** Which allowlist to sanitize against {@see @/lib/sanitizeHtml}. */
        variant: SanitizeVariant;
        /**
         * The element to render the sanitized markup into. Deliberately a
         * closed set of plain HTML tags: a component here would receive the
         * markup as a `v-html` on a component, which does not render.
         */
        as?: 'span' | 'div' | 'p';
    }>(),
    { as: 'span' },
);

const sanitized = computed(() =>
    sanitizeHtml(props.html, SANITIZE_CONFIGS[props.variant]),
);
</script>

<template>
    <component :is="as" v-html="sanitized" />
</template>
