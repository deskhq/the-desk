<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Upload } from '@lucide/vue';

defineProps<{
    /** Named in the copy, so the reader knows where the files are landing. */
    channelName: string;
}>();

const page = usePage();
</script>

<template>
    <!-- Whole-pane drop target: dropping files anywhere over the channel stages
         them in the composer. Pointer-events-none so the drop lands on the
         pane's own handler, not the overlay. -->
    <div
        data-test="channel-drop-overlay"
        class="pointer-events-none absolute inset-2.5 z-20 flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed border-brass bg-card/75 backdrop-blur-[2px]"
    >
        <span
            class="flex size-14 items-center justify-center rounded-full bg-foreground text-brass"
        >
            <Upload class="size-6" />
        </span>
        <span class="font-serif text-2xl font-semibold text-foreground">
            {{ $t('Drop to attach to #:channel', { channel: channelName }) }}
        </span>
        <span class="text-[13px] text-muted-foreground">
            {{
                $t('Up to :count files · :size MB each', {
                    count: page.props.attachments.maxPerMessage,
                    size: page.props.attachments.maxSizeMb,
                })
            }}
        </span>
    </div>
</template>
