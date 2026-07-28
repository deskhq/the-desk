<script setup lang="ts">
import { ArrowUp } from '@lucide/vue';

defineProps<{
    /** Whether the unread boundary is still above the reader's window. */
    show: boolean;
}>();

defineEmits<{
    /** The pill was clicked: scroll the boundary into view. */
    jump: [];
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
        <!-- eslint-disable-next-line local/no-raw-button -- jump pill: stays raw with the deferred jump-to-latest pills (#316); the primitive does not compose as a <Transition> child here -->
        <button
            v-if="show"
            type="button"
            data-test="jump-to-unread"
            class="absolute top-2.5 left-1/2 z-10 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-rose-600 px-3 py-1 text-[12px] font-semibold text-white shadow-md hover:bg-rose-700"
            @click="$emit('jump')"
        >
            <ArrowUp class="size-3.5" />
            {{ $t('New messages') }}
        </button>
    </Transition>
</template>
