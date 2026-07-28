<script setup lang="ts">
import { formatDayLabel } from '@/lib/datetime';

defineProps<{
    /** The crossing message's timestamp, labelled relative to the viewer's today. */
    iso: string;
}>();
</script>

<template>
    <div data-test="day-divider" class="my-4 flex items-center gap-3">
        <span aria-hidden="true" class="h-px flex-1 bg-border" />
        <span class="font-serif text-[13px] text-muted-foreground italic">
            {{ formatDayLabel(iso) }}
        </span>
        <span aria-hidden="true" class="h-px flex-1 bg-border" />
    </div>
</template>
