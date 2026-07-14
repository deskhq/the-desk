<script setup lang="ts">
import { computed } from 'vue';
import { getInitials } from '@/composables/useInitials';
import { memberAvatarStack } from '@/lib/memberAvatars';
import type { StackMember } from '@/lib/memberAvatars';

const props = withDefaults(
    defineProps<{
        /**
         * People to stack. The first `max` render as overlapping initials
         * avatars; the rest collapse into a trailing "+N" chip.
         */
        members: StackMember[];
        max?: number;
        size?: 'sm' | 'md';
        /**
         * Ring colour class matching the surface the stack sits on, so the ring
         * reads as the gap between overlapping avatars (e.g. `ring-card`,
         * `ring-sidebar`, `ring-sidebar-primary`).
         */
        ringClass?: string;
    }>(),
    { max: 3, size: 'md', ringClass: 'ring-card' },
);

const stack = computed(() => memberAvatarStack(props.members, props.max));

// Per-size geometry. Fills are always opaque (`bg-muted`, never a translucent
// tint) so the overlap of two avatars never stacks two semi-transparent layers
// into a darker crescent where they intersect.
const SIZES = {
    sm: {
        box: 'size-4.5 -ml-1.5 first:ml-0',
        text: 'text-[8px]',
        overflowText: 'text-[7.5px]',
    },
    md: {
        box: 'size-7 -ml-2.5 first:ml-0',
        text: 'text-[10px]',
        overflowText: 'text-[10px]',
    },
} as const;

const dims = computed(() => SIZES[props.size]);
</script>

<template>
    <span class="flex shrink-0" aria-hidden="true">
        <span
            v-for="member in stack.visible"
            :key="member.id"
            class="flex items-center justify-center rounded-full bg-muted font-semibold text-primary ring-2 select-none"
            :class="[dims.box, dims.text, ringClass]"
            >{{ getInitials(member.name) }}</span
        >
        <span
            v-if="stack.overflow > 0"
            class="flex items-center justify-center rounded-full bg-muted font-semibold text-muted-foreground ring-2 select-none"
            :class="[dims.box, dims.overflowText, ringClass]"
            >+{{ stack.overflow }}</span
        >
    </span>
</template>
