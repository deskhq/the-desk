<script setup lang="ts">
import { Lock } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const { locked = false, ...props } = defineProps<{
    class?: HTMLAttributes['class'];
    /**
     * Renders the field as settled rather than editable — a sand fill, a lock
     * glyph, and no keyboard entry. Used for the address an invitation was sent
     * to, which the server enforces anyway.
     */
    locked?: boolean;
}>();
</script>

<template>
    <div class="relative">
        <Input
            :class="
                cn(
                    'h-12 rounded-[10px] border-input px-4.5 text-base shadow-none md:text-base',
                    locked && 'bg-muted pr-11 text-muted-foreground',
                    props.class,
                )
            "
            v-bind="$attrs"
            :readonly="locked || undefined"
            :aria-readonly="locked || undefined"
        />
        <span
            v-if="locked"
            class="pointer-events-none absolute inset-y-0 right-4 flex items-center"
            aria-hidden="true"
        >
            <slot name="badge">
                <Lock class="size-3.75 text-muted-foreground" />
            </slot>
        </span>
    </div>
</template>
