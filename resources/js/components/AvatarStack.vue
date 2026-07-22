<script setup lang="ts">
import { computed } from 'vue';
import PresenceDot from '@/components/PresenceDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { getInitials } from '@/composables/useInitials';
import { memberAvatarStack } from '@/lib/memberAvatars';
import type { StackMember } from '@/lib/memberAvatars';
import type { RenderedPresence } from '@/lib/presence';

const props = withDefaults(
    defineProps<{
        /**
         * People to stack. The first `max` render as overlapping avatars (their
         * image, or initials when they have none); the rest collapse into a
         * trailing "+N" chip.
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
        /**
         * How each stacked member reads on the presence roster. Omitted on the
         * stacks where presence is meaningless (a poll's voters, a group DM's
         * fixed participant list), which then render no dots at all.
         */
        presenceFor?: (userId: string) => RenderedPresence;
        /**
         * Background class matching the surface behind the stack, for the hollow
         * centre of an away dot. Pairs with `ringClass`.
         */
        surfaceClass?: string;
    }>(),
    { max: 3, size: 'md', ringClass: 'ring-card', surfaceClass: 'bg-card' },
);

const stack = computed(() => memberAvatarStack(props.members, props.max));

// Per-size geometry. Avatar images and the `bg-muted` initials fallback are both
// opaque, so overlapping avatars never stack two semi-transparent layers into a
// darker crescent where they intersect.
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

// The avatar diameter each stack size draws, handed to PresenceDot so it owns
// the badge geometry (diameter, ring width, corner placement, stacking).
const DOT_SIZES = { sm: '18', md: '28' } as const;
</script>

<template>
    <span class="flex shrink-0" aria-hidden="true">
        <!-- A member with presence gets a positioned wrapper so their dot can sit
             on the avatar's corner; without one the avatar stacks directly, as
             it always has. -->
        <span
            v-for="member in stack.visible"
            :key="member.id"
            class="relative"
            :class="dims.box"
        >
            <Avatar class="size-full ring-2 select-none" :class="ringClass">
                <AvatarImage
                    v-if="member.avatar"
                    :src="member.avatar"
                    :alt="member.name"
                />
                <AvatarFallback
                    class="font-semibold text-primary"
                    :class="dims.text"
                >
                    {{ getInitials(member.name) }}
                </AvatarFallback>
            </Avatar>
            <PresenceDot
                v-if="presenceFor"
                data-test="stack-presence-dot"
                :presence="presenceFor(member.id)"
                :surface-class="surfaceClass"
                :size="DOT_SIZES[size]"
                :class="ringClass"
            />
        </span>
        <span
            v-if="stack.overflow > 0"
            class="flex items-center justify-center rounded-full bg-muted font-semibold text-muted-foreground ring-2 select-none"
            :class="[dims.box, dims.overflowText, ringClass]"
            >+{{ stack.overflow }}</span
        >
    </span>
</template>
