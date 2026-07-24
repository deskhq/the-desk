<script setup lang="ts" generic="T extends string">
import type { Component } from 'vue';
import { computed, ref } from 'vue';

/**
 * A single segment in the control: its stored value, an accessible name, and the
 * icon drawn inside the icon-only pill.
 */
type SegmentOption = {
    value: T;
    label: string;
    icon: Component;
};

const props = defineProps<{
    /** The currently selected value; the matching segment reads as checked. */
    modelValue: T;
    options: SegmentOption[];
    /** Names the radiogroup for assistive tech (e.g. "Theme"). */
    ariaLabel: string;
    /**
     * Render the plain radio-group pattern instead of menu roles. The default
     * `menuitemradio`s are only valid ARIA inside a parent `role="menu"`, so a
     * surface with no menu ancestor — the mobile bottom sheet — must opt in.
     */
    standalone?: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: T];
}>();

/** Each segment's button, so arrow-key navigation can move focus. */
const segments = ref<HTMLButtonElement[]>([]);

const activeIndex = computed(() =>
    props.options.findIndex((option) => option.value === props.modelValue),
);

/** The segment that Tab lands on: the checked one, or the first as a fallback. */
const tabbableIndex = computed(() =>
    activeIndex.value >= 0 ? activeIndex.value : 0,
);

function select(index: number): void {
    const option = props.options[index];

    if (option && option.value !== props.modelValue) {
        emit('update:modelValue', option.value);
    }
}

function focusSegment(index: number): void {
    select(index);
    segments.value[index]?.focus();
}

/**
 * Drive the group from the keyboard per the radio-group pattern: arrows (and
 * Home/End) move and select, Space/Enter re-selects the focused segment. The
 * event is swallowed so the enclosing dropdown neither runs its own arrow
 * roving/typeahead nor closes on Enter — the menu stays open under the cursor.
 */
function onKeydown(event: KeyboardEvent, index: number): void {
    const count = props.options.length;
    let next: number;

    switch (event.key) {
        case 'ArrowRight':
        case 'ArrowDown':
            next = (index + 1) % count;
            break;
        case 'ArrowLeft':
        case 'ArrowUp':
            next = (index - 1 + count) % count;
            break;
        case 'Home':
            next = 0;
            break;
        case 'End':
            next = count - 1;
            break;
        case ' ':
        case 'Enter':
            next = index;
            break;
        default:
            return;
    }

    event.preventDefault();
    event.stopPropagation();
    focusSegment(next);
}
</script>

<template>
    <!--
      Icon-only segmented control in the app's pill-track pattern (30×24 pills on
      an inset muted track). The active segment takes the menu's pressed-row
      treatment — an ink/cream `bg-primary` fill with a brass icon — and a single
      thumb slides between segments in 120ms. Built as a `group` of
      `menuitemradio`s so it is valid ARIA inside the parent `role="menu"`;
      selecting applies instantly and never closes the dropdown.
    -->
    <div
        :role="standalone ? 'radiogroup' : 'group'"
        :aria-label="ariaLabel"
        class="relative flex gap-px rounded-full border border-border bg-muted p-0.5"
    >
        <span
            v-if="activeIndex >= 0"
            aria-hidden="true"
            data-slot="thumb"
            class="pointer-events-none absolute top-0.5 left-0.5 h-6 w-7.5 rounded-full bg-primary shadow-[0_1px_4px_rgba(29,26,21,0.25)] transition-transform duration-[120ms] ease-out dark:shadow-[0_1px_4px_rgba(0,0,0,0.35)]"
            :style="{ transform: `translateX(${activeIndex * 31}px)` }"
        />

        <!-- eslint-disable-next-line local/no-raw-button -- Bespoke roving-tabindex menuitemradio pill; needs a raw focusable element and carries none of the Button primitive's variant/size/ring. -->
        <button
            v-for="(option, index) in options"
            :key="option.value"
            :ref="
                (el) => {
                    if (el) {
                        segments[index] = el as HTMLButtonElement;
                    }
                }
            "
            type="button"
            :role="standalone ? 'radio' : 'menuitemradio'"
            :aria-checked="option.value === modelValue"
            :aria-label="option.label"
            :title="option.label"
            :tabindex="index === tabbableIndex ? 0 : -1"
            class="relative z-10 flex h-6 w-7.5 items-center justify-center rounded-full transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-popover focus-visible:outline-none"
            :class="
                option.value === modelValue
                    ? 'text-brass'
                    : 'text-muted-foreground hover:bg-secondary hover:text-foreground'
            "
            @click="focusSegment(index)"
            @keydown="onKeydown($event, index)"
        >
            <component :is="option.icon" class="size-3" :stroke-width="2" />
        </button>
    </div>
</template>
