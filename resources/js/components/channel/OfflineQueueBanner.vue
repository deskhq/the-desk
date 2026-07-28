<script setup lang="ts">
import { WifiOff } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineProps<{
    /** How many of the viewer's sends are being held locally. */
    count: number;
}>();

defineEmits<{
    /** Throw the whole queue away rather than waiting for the reconnect. */
    discard: [];
}>();
</script>

<template>
    <!-- Offline queue banner: the socket is down and the viewer's sends are
         being held locally; they flush automatically on reconnect, or can be
         discarded here. -->
    <div
        data-test="offline-queue-banner"
        class="mx-5 mb-1 flex shrink-0 items-center gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-[12.5px] font-medium text-amber-700 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-500"
    >
        <WifiOff class="size-3.5 shrink-0" />
        <span class="min-w-0">
            {{
                count === 1
                    ? $t(
                          "You're offline. 1 message is queued and will send automatically.",
                      )
                    : $t(
                          "You're offline. :count messages are queued and will send automatically.",
                          { count },
                      )
            }}
        </span>
        <Button
            variant="unstyled"
            size="none"
            type="button"
            data-test="discard-queue"
            class="ml-auto shrink-0 font-semibold text-rose-600 hover:text-rose-700 dark:text-rose-400 dark:hover:text-rose-300"
            @click="$emit('discard')"
        >
            {{ $t('Discard queue') }}
        </Button>
    </div>
</template>
