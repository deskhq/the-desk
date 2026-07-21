<script setup lang="ts">
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, defineAsyncComponent, ref } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAppearance } from '@/composables/useAppearance';
import { useCustomEmojis } from '@/composables/useCustomEmojis';
import { useEmojiPickerA11y } from '@/composables/useEmojiPickerA11y';
import { useFrequentEmojis } from '@/composables/useFrequentEmojis';
import { useTranslations } from '@/composables/useTranslations';
import type { CustomEmojiEntry } from '@/lib/customEmoji';

defineProps<{
    /**
     * Optional label shown in a tooltip above the trigger on hover and keyboard
     * focus. When set, the trigger is composed as Tooltip → PopoverTrigger so the
     * one button anchors both the popover (on click) and the tooltip (on
     * hover/focus); this requires a TooltipProvider ancestor. Consumers that
     * don't want a tooltip omit it and get the bare trigger.
     */
    tooltip?: string;
}>();

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

const { t } = useTranslations();

// Follow "The Desk"'s active light/dark appearance instead of the library's own
// black/white auto theme. In dark mode this hands the picker the library's dark
// palette as a base, which the scoped `--v3-picker-*` token overrides then
// refine onto the warm popover surface — so the grid never renders light-on-dark.
const { resolvedAppearance } = useAppearance();
const pickerTheme = computed(() => resolvedAppearance.value);

// The picker grid is third-party DOM with no ARIA or keyboard model; the wrapper
// element hosts the enhancer that labels the search field and turns the cells
// into a keyboard-navigable listbox (see `useEmojiPickerA11y`).
const pickerRoot = ref<HTMLElement | null>(null);

useEmojiPickerA11y(pickerRoot, {
    search: t('Search emoji'),
    grid: t('Emoji'),
});

// The popover's open state, closed after a pick.
const open = ref(false);

// The workspace's custom emoji, surfaced as a "Custom" strip above the native
// picker. Selecting one emits its `:name:` token (the same opaque-string
// contract as a native glyph), so reactions store it and the composer inserts it
// with no downstream changes.
const { list: customEmojis, parseToken } = useCustomEmojis();

// The viewer's own ranked shortlist, surfaced as a strip above "Custom". Its
// entries are opaque strings just like a reaction value — a native glyph or a
// `:name:` token — so picking one goes through the same `select` contract.
const { list: frequentEmojis } = useFrequentEmojis();

/**
 * The picker's `select` event carries the chosen emoji as `.i`; surface it and
 * close the popover.
 */
function onPick(payload: { i: string }): void {
    emit('select', payload.i);
    open.value = false;
}

/**
 * Emit a chosen frequently-used entry verbatim (glyph or `:name:` token), then
 * close — identical in effect to any other pick.
 */
function onPickFrequent(emoji: string): void {
    emit('select', emoji);
    open.value = false;
}

/**
 * Surface a chosen custom emoji as its `:name:` shortcode token, then close.
 */
function onPickCustom(entry: CustomEmojiEntry): void {
    emit('select', `:${entry.name}:`);
    open.value = false;
}
</script>

<template>
    <PopoverRoot v-model:open="open">
        <Tooltip v-if="tooltip">
            <TooltipTrigger as-child>
                <PopoverTrigger as-child>
                    <slot :open="open" />
                </PopoverTrigger>
            </TooltipTrigger>
            <TooltipContent side="top" :side-offset="6">
                {{ tooltip }}
            </TooltipContent>
        </Tooltip>
        <PopoverTrigger v-else as-child>
            <slot :open="open" />
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="6"
                :collision-padding="8"
                class="emoji-picker-shell z-50 overflow-hidden rounded-2xl border bg-popover shadow-[0_10px_28px_rgba(29,26,21,0.14)] outline-none"
            >
                <div
                    v-if="frequentEmojis.length > 0"
                    data-test="frequent-emoji-strip"
                    class="border-b border-border px-3 py-2.5"
                >
                    <p
                        class="mb-1.5 text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground uppercase"
                    >
                        {{ $t('Frequently used') }}
                    </p>
                    <div class="grid grid-cols-7 gap-1">
                        <!-- eslint-disable-next-line local/no-raw-button -- bespoke emoji-grid cell -->
                        <button
                            v-for="emoji in frequentEmojis"
                            :key="emoji"
                            type="button"
                            data-test="frequent-emoji-option"
                            :title="emoji"
                            :aria-label="emoji"
                            class="flex aspect-square items-center justify-center rounded-lg text-[17px] leading-none hover:bg-accent"
                            @click="onPickFrequent(emoji)"
                        >
                            <img
                                v-if="parseToken(emoji)"
                                :src="parseToken(emoji)!.url"
                                :alt="emoji"
                                class="custom-emoji size-5"
                            />
                            <span v-else aria-hidden="true">{{ emoji }}</span>
                        </button>
                    </div>
                </div>
                <div
                    v-if="customEmojis.length > 0"
                    data-test="custom-emoji-strip"
                    class="border-b border-border px-3 py-2.5"
                >
                    <p
                        class="mb-1.5 text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground uppercase"
                    >
                        {{ $t('Custom') }}
                    </p>
                    <div
                        class="grid max-h-28 grid-cols-7 gap-1 overflow-y-auto"
                    >
                        <!-- eslint-disable-next-line local/no-raw-button -- bespoke emoji-grid cell -->
                        <button
                            v-for="entry in customEmojis"
                            :key="entry.name"
                            type="button"
                            data-test="custom-emoji-option"
                            :title="`:${entry.name}:`"
                            :aria-label="`:${entry.name}:`"
                            class="flex aspect-square items-center justify-center rounded-lg hover:bg-accent"
                            @click="onPickCustom(entry)"
                        >
                            <img
                                :src="entry.url"
                                :alt="`:${entry.name}:`"
                                class="custom-emoji size-5"
                            />
                        </button>
                    </div>
                </div>
                <div ref="pickerRoot">
                    <EmojiPicker
                        :native="true"
                        :hide-search="false"
                        :theme="pickerTheme"
                        @select="onPick"
                    />
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>

<!--
  vue3-emoji-picker themes itself through `--v3-picker-*` custom properties. We
  remap them onto "The Desk" semantic tokens so the picker follows the warm
  light/dark palette instead of its own black/white auto theme. `:theme` above
  tracks the app's resolved appearance so the library's own light/dark base
  matches ours; these scoped overrides then win on top (higher specificity) and
  paint the warm popover surface in both modes. The shell card carries the
  border and shadow, so the picker's own chrome is flattened.
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
