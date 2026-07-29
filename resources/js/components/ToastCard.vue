<script setup lang="ts">
import {
    Check,
    CircleAlert,
    LoaderCircle,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed } from 'vue';
import InkSlab from '@/components/InkSlab.vue';
import { Button } from '@/components/ui/button';
import { useToastCount } from '@/composables/useToast';
import type { ToastAction, ToastTone } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';

const props = defineProps<{
    tone: ToastTone;
    /** Verb-first, no terminal full stop. Already translated. */
    title: string;
    /** The value that was just set. Already translated. */
    detail?: string;
    /**
     * A muted continuation of the title on the same line, separated by the
     * middle dot drawn below: "Uploading 3 files · 12.4 MB". Already translated.
     */
    meta?: string;
    /**
     * A short figure set hard right in monospace — a progress percentage. It
     * takes the slot the overflow count otherwise has, and is `aria-hidden`
     * with it: see the template.
     */
    value?: string;
    action?: ToastAction;
    /** Drives the drain hairline; `Infinity` leaves the toast up. */
    duration: number;
    /**
     * Injected by vue-sonner, not by the call site: dismisses this toast, and
     * true whenever the pointer or focus is anywhere in the stack.
     */
    onCloseToast?: () => void;
    isPaused?: boolean;
}>();

const { t } = useTranslations();
const toastCount = useToastCount();

const GLYPHS = {
    success: Check,
    error: CircleAlert,
    warning: TriangleAlert,
    progress: LoaderCircle,
} as const;

/**
 * Tone is never carried by colour alone — the glyph says it too, and so does the
 * copy the call site passes.
 */
const glyph = computed(() => GLYPHS[props.tone]);

const GLYPH_TINTS = {
    success: 'bg-slab-glyph-success/18 text-slab-glyph-success',
    error: 'bg-slab-glyph-error/20 text-slab-glyph-error',
    warning: 'bg-slab-glyph-warning/18 text-slab-glyph-warning',
    progress: 'bg-primary-foreground/8 text-slab-accent',
} as const;

const glyphTint = computed(() => GLYPH_TINTS[props.tone]);

/**
 * How many other toasts are stacked behind this one. Only the front card is
 * legible when the stack is collapsed, so it is the one that has to say how many
 * more there are.
 */
const overflow = computed(() => toastCount.value - 1);

/**
 * The title's continuation, separator included, so the dot never has to be
 * carried in a translated string.
 */
const metaText = computed(() => ` · ${props.meta}`);

const drains = computed(() => Number.isFinite(props.duration));

/**
 * An error stays up until it is dismissed, so unlike every other tone it needs
 * a way to dismiss it that does not depend on the pointer reaching a swipe.
 */
const closeable = computed(() => props.tone === 'error');
</script>

<template>
    <!--
      The toast card. Its surface is the shared <InkSlab> — the same primitive
      the reminder nudge sits on — so there is one floating ink surface in the
      app rather than two.
    -->
    <InkSlab
        data-test="toast"
        :data-tone="tone"
        :tone="tone === 'error' ? 'error' : 'default'"
        :aria-live="tone === 'error' ? 'assertive' : undefined"
        :aria-atomic="tone === 'error' ? 'true' : undefined"
        class="relative flex w-[min(22rem,calc(100vw-1.25rem))] items-start gap-2.75 overflow-hidden rounded-[13px] px-3.75 py-3.5"
    >
        <span
            aria-hidden="true"
            :class="[
                'flex size-6 shrink-0 items-center justify-center rounded-full',
                glyphTint,
            ]"
        >
            <component
                :is="glyph"
                :class="[
                    'size-3.5',
                    tone === 'progress' ? 'motion-safe:animate-spin' : '',
                ]"
            />
        </span>

        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span data-test="toast-title" class="text-[13.5px] font-semibold"
                >{{ title
                }}<span
                    v-if="meta"
                    data-test="toast-meta"
                    class="font-normal text-slab-muted-foreground"
                    >{{ metaText }}</span
                ></span
            >
            <span
                v-if="detail"
                data-test="toast-detail"
                class="text-[12.5px] text-slab-muted-foreground"
                >{{ detail }}</span
            >
        </div>

        <Button
            v-if="action"
            variant="unstyled"
            size="none"
            type="button"
            data-test="toast-action"
            class="min-h-11 shrink-0 self-center border-b border-slab-accent/45 pb-px text-[12.5px] font-semibold text-slab-accent md:min-h-0"
            @click="action.run()"
        >
            {{ action.label }}
        </Button>

        <!-- One figure holds this slot. A `value` wins it: the overflow count
             is decoration, whereas the percent is what the card is about. Both
             are hidden from the announcement — the count says nothing the stack
             does not, and the percent re-fires about once a second, which
             `aria-relevant="additions text"` on sonner's region would otherwise
             read aloud every time it changed. -->
        <span
            v-if="value"
            data-test="toast-value"
            aria-hidden="true"
            class="shrink-0 self-center font-mono text-[11px] text-slab-muted-foreground"
            >{{ value }}</span
        >

        <span
            v-else-if="overflow > 0"
            data-test="toast-overflow"
            aria-hidden="true"
            class="shrink-0 self-center font-mono text-[11px] text-slab-muted-foreground"
            >+{{ overflow }}</span
        >

        <Button
            v-if="closeable"
            variant="unstyled"
            size="none"
            type="button"
            data-test="toast-close"
            :aria-label="t('Dismiss')"
            class="flex min-h-11 min-w-11 shrink-0 items-center justify-center self-center rounded-md text-primary-foreground/55 transition-colors hover:text-primary-foreground md:min-h-5 md:min-w-5"
            @click="onCloseToast?.()"
        >
            <X class="size-3" />
        </Button>

        <!-- The drain counts the dismiss timer down, and holds wherever it is
             while the pointer or focus is anywhere in the stack — the same
             signal that pauses sonner's own timer, so the two cannot disagree. -->
        <span
            v-if="drains"
            data-test="toast-drain"
            aria-hidden="true"
            class="absolute inset-x-0 bottom-0 h-0.5 bg-primary-foreground/10"
        >
            <span
                class="block h-full w-full origin-left bg-slab-accent motion-safe:animate-toast-drain"
                :style="{
                    animationDuration: `${duration}ms`,
                    animationPlayState: isPaused ? 'paused' : 'running',
                }"
            />
        </span>
    </InkSlab>
</template>
