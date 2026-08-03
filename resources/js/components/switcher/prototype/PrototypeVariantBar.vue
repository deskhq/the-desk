<script setup lang="ts">
/**
 * PROTOTYPE — throwaway. The variant switcher, deliberately ugly so nobody
 * mistakes it for the design under evaluation.
 *
 * It lives inside the dialog rather than fixed to the viewport, because the
 * palette is a modal overlay and anything outside it sits behind the scrim.
 * Arrow keys belong to the list, so cycling is ⌥← / ⌥→ or the buttons.
 */
import { onMounted, onUnmounted } from 'vue';
import { lastRun } from './paletteCommands';
import {
    cycleVariant,
    VARIANT_NAMES,
    VARIANTS,
    variant,
} from './paletteVariant';

function onKeydown(event: KeyboardEvent): void {
    if (!event.altKey) {
        return;
    }

    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        cycleVariant(-1);
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        cycleVariant(1);
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown, true));
onUnmounted(() => window.removeEventListener('keydown', onKeydown, true));
</script>

<template>
    <div
        v-if="variant"
        class="shrink-0 border-t-2 border-dashed border-fuchsia-500 bg-fuchsia-950 px-3 py-2 text-fuchsia-100"
    >
        <div class="flex items-center gap-2 font-mono text-[11px]">
            <button
                type="button"
                class="rounded bg-fuchsia-500/30 px-2 py-0.5 hover:bg-fuchsia-500/50"
                @click="cycleVariant(-1)"
            >
                ←
            </button>
            <span class="min-w-0 flex-1 truncate">
                PROTOTYPE {{ variant.toUpperCase() }} —
                {{ VARIANT_NAMES[variant] }}
                <span class="opacity-60"
                    >({{ VARIANTS.length }} variants · ⌥←/⌥→)</span
                >
            </span>
            <button
                type="button"
                class="rounded bg-fuchsia-500/30 px-2 py-0.5 hover:bg-fuchsia-500/50"
                @click="cycleVariant(1)"
            >
                →
            </button>
        </div>
        <p class="mt-1 font-mono text-[10.5px] opacity-70">
            last run: {{ lastRun || '—' }}
        </p>
    </div>
</template>
