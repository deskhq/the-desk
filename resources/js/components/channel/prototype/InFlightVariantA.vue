<script setup lang="ts">
/**
 * PROTOTYPE — throwaway (#1244). Variant A — Hold.
 *
 * Nothing swaps. The channel you were reading stays fully rendered and only the
 * progress bar moves, so a miss reads as a pause on the old conversation rather
 * than as an arrival at the new one. The control the other three are judged
 * against: it is the only variant that never shows the user a frame of a
 * channel that does not exist yet.
 */
import type { SyntheticMasthead } from './syntheticProps';

// Declared only so the host can pass it uniformly; A paints nothing from it,
// which is the whole point of A.
defineProps<{ synthetic: SyntheticMasthead }>();
</script>

<template>
    <div class="pointer-events-none absolute inset-x-0 top-0 z-30 h-0.5">
        <div class="prototype-indeterminate h-full bg-brass" />
    </div>
</template>

<style scoped>
@keyframes prototype-indeterminate {
    0% {
        transform: translateX(-100%) scaleX(0.4);
    }
    50% {
        transform: translateX(0%) scaleX(0.7);
    }
    100% {
        transform: translateX(100%) scaleX(0.4);
    }
}

.prototype-indeterminate {
    transform-origin: left;
    animation: prototype-indeterminate 1.1s ease-in-out infinite;
}
</style>
