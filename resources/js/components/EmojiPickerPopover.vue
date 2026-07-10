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
                class="emoji-picker-shell z-50 overflow-hidden rounded-2xl border bg-popover shadow-[0_10px_28px_rgba(29,26,21,0.14)] outline-none"
            >
                <EmojiPicker
                    :native="true"
                    :hide-search="false"
                    theme="light"
                    @select="onPick"
                />
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>

<!--
  vue3-emoji-picker themes itself through `--v3-picker-*` custom properties. We
  remap them onto "The Desk" semantic tokens so the picker follows the warm
  light/dark palette instead of its own black/white auto theme (hence
  `theme="light"` above — it disables the library's dark overrides so our
  token-driven values win in both modes). The shell card carries the border and
  shadow, so the picker's own chrome is flattened.
-->
<style scoped>
.emoji-picker-shell :deep(.v3-emoji-picker) {
    width: 300px;
    border: 0;
    border-radius: 0;
    box-shadow: none;
    --v3-picker-bg: var(--popover);
    --v3-picker-fg: var(--popover-foreground);
    --v3-picker-border: var(--border);
    --v3-picker-input-bg: var(--muted);
    --v3-picker-input-border: transparent;
    --v3-picker-input-focus-border: var(--brass);
    --v3-picker-emoji-hover: var(--accent);
}

.emoji-picker-shell :deep(.v3-search input) {
    height: 32px;
    padding: 0 13px;
    border-radius: 999px;
}

.emoji-picker-shell :deep(.v3-emojis button) {
    border-radius: 8px;
}

.emoji-picker-shell :deep(.v3-body-inner .v3-group h5) {
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--muted-foreground);
}
</style>
