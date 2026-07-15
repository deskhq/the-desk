<script setup lang="ts">
import { Clock } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineProps<{
    // Number of pending scheduled messages for the channel (always > 0 at the
    // call site — the chip is only rendered when there are some to view).
    count: number;
    channelName: string;
}>();

const emit = defineEmits<{
    // Open the scheduled-messages list dialog.
    view: [];
}>();
</script>

<template>
    <Button
        variant="unstyled"
        size="none"
        type="button"
        data-test="scheduled-trigger"
        class="inline-flex w-fit items-center gap-1.5 rounded-full border border-brass-border/30 bg-brass-fill px-3 py-1 text-[12px] font-semibold text-brass-fill-foreground transition-colors hover:bg-brass-fill/70"
        @click="emit('view')"
    >
        <Clock class="size-3 text-brass" />
        {{
            $t(':count scheduled for #:channel', {
                count,
                channel: channelName,
            })
        }}
        <span class="text-brass" aria-hidden="true">·</span>
        <span class="underline underline-offset-[3px]">{{ $t('View') }}</span>
    </Button>
</template>
