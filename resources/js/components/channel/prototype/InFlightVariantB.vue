<script setup lang="ts">
/**
 * PROTOTYPE — throwaway (#1244). Variant B — instant chrome, empty stage.
 *
 * What Inertia's `instant` visit gives you with nothing added: the masthead and
 * the composer paint immediately from props the client already holds, and the
 * timeline is simply absent because no message has an honest client-side source.
 *
 * The purest form of the mechanism, and the one whose failure mode we most need
 * to see — an empty stage under a real masthead is indistinguishable from a
 * channel that genuinely has no messages.
 */
import ChannelMasthead from '@/components/ChannelMasthead.vue';
import type { SyntheticMasthead } from './syntheticProps';

const props = defineProps<{ synthetic: SyntheticMasthead }>();
</script>

<template>
    <div class="absolute inset-0 z-30 flex flex-col bg-background">
        <ChannelMasthead v-bind="props.synthetic" />

        <div class="min-h-0 flex-1" />

        <!-- A static stand-in for the composer: the real one needs page props
             that do not exist yet, but its footprint is what keeps the layout
             from reflowing when the response lands. -->
        <div class="shrink-0 px-4 pb-4 @2xl:px-7">
            <div
                class="rounded-xl border border-border bg-card px-4 py-3 text-[14px] text-muted-foreground"
            >
                {{ $t('Message :name', { name: props.synthetic.title }) }}
            </div>
        </div>
    </div>
</template>
