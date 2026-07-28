<script setup lang="ts">
import { ArrowDown, ArrowUp } from '@lucide/vue';
import { computed } from 'vue';
import { analyticsTiles, toneClass } from '@/lib/analyticsTiles';
import type { WorkspaceAnalytics } from '@/types';

type Props = {
    analytics: WorkspaceAnalytics;
};

const props = defineProps<Props>();

const tiles = computed(() => analyticsTiles(props.analytics));
</script>

<template>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div
            v-for="tile in tiles"
            :key="tile.key"
            class="flex flex-col gap-2 rounded-xl border border-border bg-card p-4 shadow-[0_2px_8px_rgba(29,26,21,0.05)]"
            :data-test="`analytics-stat-${tile.key}`"
        >
            <span
                class="text-[11px] font-semibold tracking-[0.07em] text-muted-foreground uppercase"
            >
                {{ tile.label }}
            </span>
            <div class="flex items-baseline gap-2">
                <span
                    class="font-serif text-3xl leading-none font-semibold tracking-tight"
                >
                    {{ tile.value }}
                </span>
                <span class="text-xs text-muted-foreground">{{
                    tile.meta
                }}</span>
            </div>
            <span
                class="inline-flex items-center gap-1 text-xs font-medium"
                :class="toneClass(tile.delta)"
            >
                <ArrowUp
                    v-if="tile.delta !== null && tile.delta > 0"
                    class="size-3"
                />
                <ArrowDown
                    v-else-if="tile.delta !== null && tile.delta < 0"
                    class="size-3"
                />
                {{ tile.deltaText }}
            </span>
        </div>
    </div>
</template>
