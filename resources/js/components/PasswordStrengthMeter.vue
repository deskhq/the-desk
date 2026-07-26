<script setup lang="ts">
import { computed } from 'vue';
import {
    SEGMENT_COUNT,
    usePasswordStrength,
} from '@/composables/usePasswordStrength';

const props = defineProps<{
    /** The password being typed, scored live. */
    password: string;
}>();

const password = computed(() => props.password);

const { score, label } = usePasswordStrength(password);

const segments = computed(() =>
    Array.from(
        { length: SEGMENT_COUNT },
        (_, index) => index < (score.value ?? 0),
    ),
);

/**
 * The leading filled segment is the lighter brass and the ones behind it the
 * deeper shade, so the bar reads as a level climbed to rather than a flat block
 * of colour.
 */
function segmentClass(filled: boolean, index: number): string {
    if (!filled) {
        return 'bg-border';
    }

    return index === (score.value ?? 0) - 1 ? 'bg-brass' : 'bg-brass-border';
}
</script>

<template>
    <!--
      Advisory only: the score never gates the submit button. The server's
      password rules decide what is acceptable; this just tells someone how far
      they have got while they are still typing.
    -->
    <div
        v-if="password !== ''"
        class="flex items-center gap-2.5"
        data-test="password-strength"
        role="status"
    >
        <div class="flex flex-1 gap-1">
            <span
                v-for="(filled, index) in segments"
                :key="index"
                class="h-1 flex-1 rounded-full transition-colors"
                :class="segmentClass(filled, index)"
            />
        </div>
        <span
            v-if="label"
            class="shrink-0 text-xs font-semibold text-brass-fill-foreground"
        >
            {{ label }}
        </span>
    </div>
</template>
