<script setup lang="ts">
import type { LinkComponentBaseProps, Method } from '@inertiajs/core';
import { Link } from '@inertiajs/vue3';

type Props = {
    href: LinkComponentBaseProps['href'];
    tabindex?: number;
    method?: Method;
    as?: string;
};

defineProps<Props>();
</script>

<template>
    <Link
        :href="href"
        :tabindex="tabindex"
        :method="method"
        :as="as"
        class="text-brass-fill-foreground underline decoration-brass-border/40 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-brass-border"
    >
        <slot />
    </Link>
</template>
