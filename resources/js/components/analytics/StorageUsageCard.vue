<script setup lang="ts">
import { computed } from 'vue';
import { storageReadout } from '@/lib/storageUsage';
import type { TeamStorage } from '@/types';

type Props = {
    storage: TeamStorage;
};

const props = defineProps<Props>();

const readout = computed(() => storageReadout(props.storage));
</script>

<template>
    <section
        class="rounded-xl border border-border bg-card p-5 shadow-[0_2px_8px_rgba(29,26,21,0.05)]"
        data-test="analytics-storage"
    >
        <div class="mb-4 flex items-baseline gap-2.5">
            <h3 class="text-sm font-semibold">{{ $t('Storage') }}</h3>
            <span class="text-xs text-muted-foreground">{{
                $t('uploads across this workspace')
            }}</span>
            <span
                class="ml-auto text-xs font-medium tabular-nums"
                :class="readout.toneClass"
                data-test="analytics-storage-percent"
            >
                {{ readout.percentText }}
            </span>
        </div>

        <div
            class="h-1.5 overflow-hidden rounded-full bg-muted"
            role="progressbar"
            :aria-label="$t('Storage used')"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="readout.barPercent"
            :aria-valuetext="readout.sizeText"
        >
            <div
                class="h-full rounded-full"
                :style="{
                    width: `${readout.barPercent}%`,
                    background: readout.barColor,
                }"
            ></div>
        </div>

        <div class="mt-2 flex items-baseline gap-2 text-xs">
            <span
                class="text-muted-foreground tabular-nums"
                data-test="analytics-storage-size"
            >
                {{ readout.sizeText }}
            </span>
            <span
                class="ml-auto tabular-nums"
                :class="readout.toneClass"
                data-test="analytics-storage-remaining"
            >
                {{ readout.remainingText }}
            </span>
        </div>
    </section>
</template>
