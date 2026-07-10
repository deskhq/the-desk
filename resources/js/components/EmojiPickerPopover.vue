<script setup lang="ts">
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { defineAsyncComponent, ref } from 'vue';

// The emoji picker touches `indexedDB` at module load, which doesn't exist under
// Node SSR, so import it lazily on the client only — its loader runs when the
// popover first opens (a client-only interaction), never during server render.
const EmojiPicker = defineAsyncComponent(async () => {
    await import('vue3-emoji-picker/css');

    return (await import('vue3-emoji-picker')).default;
});

const emit = defineEmits<{
    select: [emoji: string];
}>();

// The popover's open state, closed after a pick.
const open = ref(false);

/**
 * The picker's `select` event carries the chosen emoji as `.i`; surface it and
 * close the popover.
 */
function onPick(payload: { i: string }): void {
    emit('select', payload.i);
    open.value = false;
}
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child>
            <slot :open="open" />
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="6"
                :collision-padding="8"
                class="z-50 outline-none"
            >
                <EmojiPicker
                    :native="true"
                    :hide-search="false"
                    theme="auto"
                    @select="onPick"
                />
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
