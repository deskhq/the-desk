<script setup lang="ts">
defineProps<{
    /** Whether an older page is in flight. */
    show: boolean;
}>();
</script>

<template>
    <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="show"
            data-test="loading-older"
            class="pointer-events-none absolute inset-x-0 top-2 z-10 flex justify-center"
        >
            <span
                class="inline-flex items-center gap-2 rounded-full bg-card px-3 py-1 text-[12px] text-muted-foreground shadow-sm ring-1 ring-border"
            >
                <span
                    aria-hidden="true"
                    class="size-3 animate-spin rounded-full border-2 border-border border-t-foreground"
                />
                {{ $t('Loading earlier messages…') }}
            </span>
        </div>
    </Transition>
</template>
