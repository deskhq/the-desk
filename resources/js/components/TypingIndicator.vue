<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    names: string[];
}>();

/**
 * Human-readable summary of who is typing: one or two names by name, and a
 * generic phrasing once a third joins so the line never grows unbounded.
 */
const label = computed<string>(() => {
    const [first, second] = props.names;

    if (props.names.length === 1) {
        return `${first} is typing`;
    }

    if (props.names.length === 2) {
        return `${first} and ${second} are typing`;
    }

    return 'Several people are typing';
});
</script>

<template>
    <!-- Fixed height reserves the line so the composer never jumps as it toggles. -->
    <div
        class="flex h-5 items-center gap-1.5 px-1.5 font-serif text-xs text-muted-foreground italic"
        aria-live="polite"
        data-test="typing-indicator"
    >
        <template v-if="names.length > 0">
            <!-- Three dots rippling out of phase, graduating from light to dark —
                 the classic "typing" tell rendered in the sand palette. -->
            <span class="flex items-end gap-0.5" aria-hidden="true">
                <span
                    class="size-1 animate-bounce rounded-full bg-muted-foreground/40 [animation-delay:-0.3s]"
                />
                <span
                    class="size-1 animate-bounce rounded-full bg-muted-foreground/65 [animation-delay:-0.15s]"
                />
                <span
                    class="size-1 animate-bounce rounded-full bg-muted-foreground/90"
                />
            </span>
            <span class="truncate">{{ label }}</span>
        </template>
    </div>
</template>
