<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';

const { appearance, updateAppearance } = useAppearance();

const tabs = [
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
    { value: 'system', label: 'System' },
] as const;
</script>

<template>
    <div
        class="inline-flex w-full max-w-sm gap-1.5 rounded-full bg-muted p-[3px]"
    >
        <button
            v-for="{ value, label } in tabs"
            :key="value"
            @click="updateAppearance(value)"
            :class="[
                'flex h-7 flex-1 items-center justify-center rounded-full text-xs font-medium transition-colors',
                appearance === value
                    ? 'border border-border bg-card text-foreground shadow-xs'
                    : 'text-muted-foreground hover:text-foreground',
            ]"
        >
            {{ label }}
        </button>
    </div>
</template>
