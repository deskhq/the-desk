<script setup lang="ts">
import { ArrowRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';

const { type = 'submit', loading = false } = defineProps<{
    /** Overridden to `button` by the passkey ceremony, which submits nothing. */
    type?: 'submit' | 'button';
    /** Swaps the arrow for a spinner and blocks a second press. */
    loading?: boolean;
}>();
</script>

<template>
    <!--
      The paper column's one ink control. The arrow is decorative — the label
      already says where the button goes — so assistive tech never sees it.
    -->
    <Button
        :type="type"
        class="h-14.5 w-full rounded-full text-base font-medium shadow-[0_6px_18px_-4px_var(--shadow-ink)]"
        :loading="loading"
    >
        <slot />
        <ArrowRight v-if="!loading" class="text-brass" aria-hidden="true" />
    </Button>
</template>
