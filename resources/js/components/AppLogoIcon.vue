<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!--
      "The stack" — three isometric planes (papers on a desk). The lower two
      planes ride on `currentColor` (so the mark adapts to whatever surface it
      sits on, light or dark), while the top plane is always brass.
    -->
    <svg
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
