<script setup lang="ts">
import { computed } from 'vue';
import { matchRange } from '@/composables/quickSwitcher';

const props = defineProps<{
    /** The result's name, as the row reads it out. */
    name: string;
    /** The raw query, whose contiguous match is the run to brighten. */
    query: string;
    /**
     * Whether to mark the match at all. The mobile rows brighten it; the desktop
     * palette leaves the name whole.
     */
    highlight: boolean;
}>();

/** A run of a result's name, marked when it is the part the query matched. */
type NameSegment = { text: string; highlighted: boolean };

/**
 * Split a name around the typed query's contiguous match so the mobile rows
 * can brighten it; a single unhighlighted run when nothing contiguous matches.
 */
const segments = computed<NameSegment[]>(() => {
    const range = matchRange(props.name, props.query);

    if (range === null) {
        return [{ text: props.name, highlighted: false }];
    }

    return [
        { text: props.name.slice(0, range.start), highlighted: false },
        {
            text: props.name.slice(range.start, range.start + range.length),
            highlighted: true,
        },
        {
            text: props.name.slice(range.start + range.length),
            highlighted: false,
        },
    ].filter((segment) => segment.text !== '');
});
</script>

<template>
    <span class="truncate max-md:text-[15px]">
        <template v-if="highlight">
            <template v-for="(segment, index) in segments" :key="index">
                <span
                    v-if="segment.highlighted"
                    data-test="quick-switcher-match"
                    class="rounded-[3px] bg-brass/25"
                    >{{ segment.text }}</span
                >
                <template v-else>{{ segment.text }}</template>
            </template>
        </template>
        <template v-else>{{ name }}</template>
    </span>
</template>
