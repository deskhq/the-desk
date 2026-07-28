<script setup lang="ts">
defineProps<{
    /**
     * The day the topmost visible row falls in, or null when the chip should
     * stay down (pinned to the newest message, or a day divider already leads).
     */
    label: string | null;
}>();
</script>

<template>
    <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="-translate-y-1 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="-translate-y-1 opacity-0"
    >
        <div
            v-if="label"
            data-test="sticky-date"
            class="pointer-events-none absolute top-2.5 left-1/2 z-10 inline-flex h-6.5 -translate-x-1/2 items-center rounded-full bg-card px-3.5 text-[11.5px] font-semibold text-muted-foreground shadow-md ring-1 ring-border"
        >
            {{ label }}
        </div>
    </Transition>
</template>
