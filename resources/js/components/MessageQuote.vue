<script setup lang="ts">
import { CornerUpLeft } from '@lucide/vue';
import { computed } from 'vue';
import { messageBodyPreview } from '@/lib/messageBody';

const props = defineProps<{
    authorName: string;
    body: string;
    isDeleted: boolean;
}>();

// A one-line, plain-text snippet of the quoted body; empty for a deleted parent,
// whose body is never sent to the client.
const preview = computed(() =>
    props.isDeleted ? '' : messageBodyPreview(props.body),
);
</script>

<template>
    <span class="flex min-w-0 items-center gap-1.5 text-[12.5px] leading-tight">
        <CornerUpLeft
            class="size-3 shrink-0 text-muted-foreground/60"
            aria-hidden="true"
        />
        <span
            v-if="isDeleted"
            data-test="quote-deleted"
            class="truncate text-muted-foreground/70 italic"
        >
            Original message was deleted
        </span>
        <template v-else>
            <span class="shrink-0 font-semibold text-muted-foreground">{{
                authorName
            }}</span>
            <span class="truncate text-muted-foreground/80">{{ preview }}</span>
        </template>
    </span>
</template>
