<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        /**
         * `error` warms the slab a step. Every other tone is carried by the
         * glyph and the copy, never by the surface — see the tone rule in #978.
         */
        tone?: 'default' | 'error';
        /** Radius, padding and width are the caller's; the surface is not. */
        class?: string;
    }>(),
    { tone: 'default', class: undefined },
);

const surface = computed(() =>
    props.tone === 'error' ? 'bg-slab-error' : 'bg-primary',
);
</script>

<template>
    <!--
      The app's one floating transient surface, shared by toasts and reminder
      nudges so there is a single ink slab rather than two that drift apart.

      A slab is always the inverse of what it interrupts — ink on paper in light,
      paper on ink in dark — which `--primary` / `--primary-foreground` already
      deliver as a shipped token pair. It has no border at all: the shadow is the
      elevation, and a hairline border is exactly what made the old pale toast
      read as a panel instead of something transient.

      Pointer events are re-enabled here because the rail that positions these
      ignores them, so its gaps never block the app behind it.
    -->
    <div
        :class="
            cn(
                'pointer-events-auto text-primary-foreground shadow-slab',
                surface,
                props.class,
            )
        "
    >
        <slot />
    </div>
</template>
