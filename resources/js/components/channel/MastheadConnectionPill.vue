<script setup lang="ts">
import { Check } from '@lucide/vue';
import type { ConnectionPill } from '@/composables/useConnectionState';

/**
 * The realtime connection cue: a reconnecting pill, a transient back-online
 * confirmation, or nothing when the socket is steadily connected.
 */
const props = defineProps<{
    pill?: ConnectionPill;
}>();
</script>

<template>
    <!-- A quiet amber pill while reconnecting, flipping to a brief green
         confirmation once the socket recovers. The app stays fully usable
         throughout. Below the breakpoint the masthead has no room for it, so it
         hangs toast-style under the header (which anchors it) over the timeline
         instead of squeezing the flex row — pointer-events pass through, so it
         never blocks the first message row it floats over. -->
    <span
        v-if="props.pill === 'reconnecting'"
        data-test="connection-reconnecting"
        role="status"
        class="pointer-events-none absolute top-full left-1/2 mt-2 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-3.5 py-1.5 text-[13px] font-semibold text-amber-700 shadow-md md:static md:mt-0 md:translate-x-0 md:px-2.5 md:py-1 md:text-[11.5px] md:shadow-none dark:border-amber-900/50 dark:bg-amber-950 dark:text-amber-500 md:dark:bg-amber-950/40"
    >
        <span class="size-1.5 animate-pulse rounded-full bg-amber-500" />
        {{ $t('Reconnecting')
        }}<span aria-hidden="true" class="inline-flex w-2.5 justify-start"
            ><span class="animate-pulse [animation-delay:0ms]">.</span
            ><span class="animate-pulse [animation-delay:200ms]">.</span
            ><span class="animate-pulse [animation-delay:400ms]">.</span></span
        >
    </span>
    <span
        v-else-if="props.pill === 'back-online'"
        data-test="connection-back-online"
        role="status"
        class="pointer-events-none absolute top-full left-1/2 mt-2 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-3.5 py-1.5 text-[13px] font-semibold text-emerald-700 shadow-md md:static md:mt-0 md:translate-x-0 md:px-2.5 md:py-1 md:text-[11.5px] md:shadow-none dark:border-emerald-900/50 dark:bg-emerald-950 dark:text-emerald-500 md:dark:bg-emerald-950/40"
    >
        <Check class="size-3" />
        {{ $t('Back online') }}
    </span>
</template>
