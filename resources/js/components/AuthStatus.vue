<script setup lang="ts">
import { CircleCheck } from '@lucide/vue';
</script>

<template>
    <div
        class="flex items-center gap-2.5 rounded-[10px] border border-emerald-600/30 bg-emerald-600/10 px-3.5 py-2.5 text-sm text-emerald-800 dark:text-emerald-300"
    >
        <CircleCheck class="size-4 shrink-0 text-emerald-600" />
        <span class="leading-snug"><slot /></span>
    </div>
</template>
