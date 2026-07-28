<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();

const page = usePage();

/**
 * The operator's own mark, or null on an instance that still ships ours. An
 * uploaded file cannot follow `currentColor` the way the inline mark below
 * does, so it is only rendered once the operator has actually supplied one.
 */
const logo = computed(() => page.props.branding.logo);
</script>

<template>
    <!--
      An operator-supplied mark, and a fixed-colour asset: unlike the inline
      mark it cannot adapt to the surface it sits on. That tradeoff is the one
      documented for whitelabeling.
    -->
    <img v-if="logo" :src="logo" alt="" :class="className" v-bind="$attrs" />

    <!--
      "The stack" — three isometric planes (papers on a desk). The lower two
      planes ride on `currentColor` (so the mark adapts to whatever surface it
      sits on, light or dark), while the top plane is always brass.
    -->
    <svg
        v-else
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 40"
        :class="className"
        v-bind="$attrs"
    >
        <polygon
            points="20,18 36,27 20,36 4,27"
            fill="currentColor"
            opacity="0.4"
        />
        <polygon
            points="20,11 36,20 20,29 4,20"
            fill="currentColor"
            opacity="0.7"
        />
        <polygon points="20,4 36,13 20,22 4,13" class="fill-brass" />
    </svg>
</template>
