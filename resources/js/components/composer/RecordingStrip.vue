<script setup lang="ts">
import { Square, X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { VOICE_MAX_DURATION_SECONDS, formatClock } from '@/lib/audio';

const props = defineProps<{
    /** How long the mic has been open, in seconds. */
    elapsedSeconds: number;
    /** Whether the clip is inside the final thirty seconds of its budget. */
    isNearingLimit: boolean;
    /** The live input level, 0–1, driving the meter. */
    level: number;
}>();

defineEmits<{
    /** Throw the recording away. */
    cancel: [];
    /** Stop the mic and stage the clip in the tray. */
    stop: [];
}>();

/** The strip's `elapsed / 5:00` ceiling. */
const recordingLimit = formatClock(VOICE_MAX_DURATION_SECONDS);

/**
 * The live input-level meter's bars (design 1b). Purely ephemeral chrome: the
 * levels are read from the mic in real time and nothing is kept with the clip.
 */
const LEVEL_BARS = 10;
const levelBars = computed(() =>
    Array.from({ length: LEVEL_BARS }, (_, index) => {
        // Stagger the bars around the current level so the meter reads as a
        // moving waveform rather than a single block rising and falling.
        const offset = ((index % 3) + 1) / 4;

        return Math.min(Math.max(props.level * (0.5 + offset), 0.1), 1);
    }),
);
</script>

<template>
    <!-- While the mic is open the input row gives way to a live readout — a
         pulsing record dot, the elapsed time against the five-minute cap, an
         ephemeral input-level meter, and discard/stage controls. -->
    <div
        data-test="composer-recording"
        class="flex h-13 items-center gap-2.5 py-2 pr-2 pl-4.5"
    >
        <span
            class="size-2.5 shrink-0 animate-pulse rounded-full bg-destructive"
            aria-hidden="true"
        ></span>
        <span
            data-test="composer-recording-elapsed"
            :data-warning="isNearingLimit ? 'true' : 'false'"
            class="text-sm font-semibold tabular-nums"
            :class="
                isNearingLimit ? 'text-destructive-text' : 'text-foreground'
            "
            aria-live="off"
        >
            {{ formatClock(elapsedSeconds) }}
        </span>
        <span class="text-[12.5px] text-muted-foreground tabular-nums">
            / {{ recordingLimit }}
        </span>
        <div
            class="flex min-w-0 flex-1 items-center gap-0.75 px-1.5"
            aria-hidden="true"
        >
            <span
                v-for="(bar, index) in levelBars"
                :key="index"
                class="w-0.75 rounded-full bg-destructive/60"
                :style="{ height: `${bar * 20}px` }"
            ></span>
        </div>
        <Button
            variant="ghost"
            size="icon"
            data-test="composer-recording-cancel"
            class="size-8.5 shrink-0 rounded-full text-muted-foreground max-md:size-11"
            :aria-label="$t('Discard recording')"
            @click="$emit('cancel')"
        >
            <X class="size-3.75" />
        </Button>
        <Button
            size="icon"
            data-test="composer-recording-stop"
            class="size-8.5 shrink-0 rounded-full bg-primary text-brass hover:bg-primary/90 max-md:size-11"
            :aria-label="$t('Stop recording')"
            @click="$emit('stop')"
        >
            <Square class="size-3" fill="currentColor" />
        </Button>
    </div>
</template>
