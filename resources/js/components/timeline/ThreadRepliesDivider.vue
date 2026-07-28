<script setup lang="ts">
import { computed } from 'vue';
import { useTranslations } from '@/composables/useTranslations';

const props = defineProps<{
    /** How many replies the rule stands above. */
    count: number;
}>();

const { t } = useTranslations();

/**
 * The rule's label, shared by its visible text and the separator's accessible
 * name.
 */
const label = computed(() =>
    props.count === 1
        ? t(':count reply', { count: 1 })
        : t(':count replies', { count: props.count }),
);
</script>

<template>
    <!-- The mobile thread push moves the reply count out of the panel header and
         into this rule under the root, separating the parent message from its
         replies (design m3). -->
    <div
        data-test="thread-replies-divider"
        role="separator"
        :aria-label="label"
        class="mt-4 flex items-center gap-2.5"
    >
        <span
            aria-hidden="true"
            class="text-[11px] font-semibold tracking-[0.06em] whitespace-nowrap text-muted-foreground uppercase"
        >
            {{ label }}
        </span>
        <span aria-hidden="true" class="h-px flex-1 bg-border" />
    </div>
</template>
