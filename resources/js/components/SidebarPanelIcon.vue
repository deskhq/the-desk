<script setup lang="ts">
type Props = {
    /**
     * Inks the left rail brass, so the glyph reports "something is waiting"
     * before any numeral does — and still reports it when the unread is the
     * ordinary kind that earns no numeral at all.
     */
    unread?: boolean;
};

withDefaults(defineProps<Props>(), { unread: false });
</script>

<template>
    <!--
      The mobile sidebar toggle's glyph: Lucide's `PanelLeftOpen` panel with the
      chevron dropped and the left rail inked instead. The chevron is a desktop
      metaphor — on a phone the sheet it would point at is the whole screen —
      and it fought the unread badge for the same corner.
    -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        aria-hidden="true"
    >
        <rect
            x="3.25"
            y="4.25"
            width="17.5"
            height="15.5"
            rx="3.25"
            stroke="currentColor"
            stroke-width="1.5"
        />
        <path d="M9 4.75v14.5" stroke="currentColor" stroke-width="1.5" />
        <rect
            data-test="sidebar-panel-rail"
            :data-unread="unread"
            x="4.9"
            y="5.9"
            width="2.6"
            height="12.2"
            rx="1.3"
            :class="unread ? 'fill-brass' : 'fill-current opacity-[0.38]'"
        />
    </svg>
</template>
