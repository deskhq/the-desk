<script setup lang="ts">
/**
 * The uppercase "Bot" tag that rides beside a non-human author's name.
 *
 * Shared by every surface that shows a message author, because the marking is
 * indelible: a per-message identity override changes the displayed name and icon
 * but never removes this, so a reader always sees that the row is non-human
 * whatever the name says.
 */
withDefaults(
    defineProps<{
        /**
         * Which surface the badge sits on. `slab` takes the ink slab's own muted
         * token — the slab inverts between themes, so the page's
         * `muted-foreground` would fall under the contrast floor on it.
         */
        surface?: 'page' | 'slab';
    }>(),
    { surface: 'page' },
);
</script>

<template>
    <span
        data-test="author-bot-badge"
        class="inline-flex items-center rounded border px-1.5 text-[9px] font-bold tracking-[0.08em] uppercase"
        :class="
            surface === 'slab'
                ? 'border-slab-muted-foreground text-slab-muted-foreground'
                : 'border-border text-muted-foreground'
        "
        >{{ $t('Bot') }}</span
    >
</template>
