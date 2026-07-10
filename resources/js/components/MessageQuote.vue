<script setup lang="ts">
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
    <span
        class="flex min-w-0 items-baseline gap-1.5 border-l-2 pl-2.5 text-[12.5px] leading-tight"
        :class="isDeleted ? 'border-border' : 'border-brass'"
    >
        <span
            v-if="isDeleted"
            data-test="quote-deleted"
            class="truncate font-serif text-muted-foreground/70 italic"
        >
            Original message was deleted
        </span>
        <template v-else>
            <span class="shrink-0 font-semibold text-foreground/80">{{
                authorName
            }}</span>
            <span class="truncate text-muted-foreground">{{ preview }}</span>
        </template>
    </span>
</template>
