<script setup lang="ts">
/**
 * PROTOTYPE — throwaway (#1244). Variant D — crossfade.
 *
 * The chrome swaps instantly, the stage does not. The masthead is the target
 * channel's, painted from the shell, while the conversation you were reading
 * holds underneath behind a veil until the real props land.
 *
 * The only one of the four with no empty frame at any point: the eye is never
 * asked to look at nothing, and the veil says "this is on its way out" without
 * claiming the content under it belongs to the new channel.
 *
 * Its risk is the mirror of C's — the old messages are *wrong*, and a veil that
 * is too light reads as the new channel having someone else's conversation in
 * it.
 */
import ChannelMasthead from '@/components/ChannelMasthead.vue';
import type { SyntheticMasthead } from './syntheticProps';

const props = defineProps<{ synthetic: SyntheticMasthead }>();
</script>

<template>
    <div class="absolute inset-0 z-30 flex flex-col">
        <!-- Opaque: the masthead is the one region we can paint truthfully, so
             it fully replaces the old one. -->
        <div class="shrink-0 bg-background">
            <ChannelMasthead v-bind="props.synthetic" />
        </div>

        <!-- Translucent: the previous conversation reads through, dimmed and
             very slightly blurred, so continuity survives the swap. -->
        <div
            class="min-h-0 flex-1 bg-background/65 backdrop-blur-[1.5px] backdrop-saturate-50"
        />
    </div>
</template>
