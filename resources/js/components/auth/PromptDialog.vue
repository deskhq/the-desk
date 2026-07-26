<script setup lang="ts">
import type { Component } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * The frame for a one-time account-security prompt: an icon, a title, a body,
 * one field, two equal-weight actions, and a quiet footnote. Deliberately a
 * shell rather than a one-off, so a second prompt (two-factor enrolment being
 * the obvious candidate) drops into the same frame instead of redrawing it.
 *
 * Everything below the masthead is the caller's: the default slot carries the
 * field, or whatever stands in for it while a ceremony runs.
 *
 * Presentation comes from `<DialogContent>`: a centred dialog from `md` up, a
 * bottom sheet below it, where the controls stack full-width at touch height and
 * the workspace stays visible above the scrim so the sheet reads as a step
 * rather than a wall.
 */
const props = withDefaults(
    defineProps<{
        /** Whether the prompt is presented. */
        open: boolean;
        /** The icon set on the brass tile above the title. */
        icon: Component;
        title: string;
        description: string;
        /** The primary action's label, swapped to a retry label after a failure. */
        primaryLabel: string;
        /** The equal-weight way out, never a whisper. */
        secondaryLabel: string;
        /** The quiet line under the actions, e.g. where to do this later instead. */
        footnote?: string;
        /** Whether the primary action is mid-flight: disabled, and no way out. */
        busy?: boolean;
        /**
         * Whether the prompt can still be dismissed. False while a ceremony is in
         * flight: the native browser sheet owns the interaction, and closing
         * underneath it would strand the ceremony.
         */
        dismissible?: boolean;
        /** Whether to draw the actions at all — a finished prompt has no use for them. */
        showActions?: boolean;
    }>(),
    { busy: false, dismissible: true, showActions: true },
);

const emit = defineEmits<{
    'update:open': [open: boolean];
    primary: [];
    secondary: [];
}>();

/**
 * One guard for every route out: Escape, a scrim click and the sheet's
 * swipe-down all arrive here as an `update:open` of false, so refusing it here
 * closes all of them at once while the prompt is not dismissible.
 */
function onOpenChange(open: boolean): void {
    if (open || props.dismissible) {
        emit('update:open', open);
    }
}
</script>

<template>
    <Dialog :open="props.open" @update:open="onOpenChange">
        <DialogContent
            data-test="prompt-dialog"
            :show-close-button="false"
            class="gap-0 sm:max-w-[30rem]"
        >
            <span
                class="flex size-11.5 shrink-0 items-center justify-center rounded-[12px] border border-brass-border/50 bg-brass-fill text-brass-fill-foreground"
            >
                <component :is="icon" class="size-6" aria-hidden="true" />
            </span>

            <DialogTitle
                class="mt-4.5 font-serif text-[25px] leading-[1.15] font-medium tracking-[-0.01em] text-foreground md:text-[27px]"
            >
                {{ title }}
            </DialogTitle>

            <DialogDescription
                class="mt-2.5 text-[14.5px] leading-[1.6] text-muted-foreground"
            >
                {{ description }}
            </DialogDescription>

            <slot />

            <!-- Both actions carry the same size, weight and ink: a security
                 nudge that traps the reader is a worse nudge. -->
            <div
                v-if="showActions"
                class="mt-5.5 flex gap-2 max-md:flex-col md:items-center md:gap-2.5"
            >
                <Button
                    type="button"
                    class="h-10.5 rounded-full px-6 text-[14.5px] font-semibold max-md:h-12 max-md:w-full"
                    :disabled="busy || undefined"
                    data-test="prompt-primary"
                    @click="emit('primary')"
                >
                    {{ primaryLabel }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    class="h-10.5 rounded-full px-4.5 text-[14.5px] font-semibold text-foreground max-md:h-12 max-md:w-full"
                    data-test="prompt-secondary"
                    @click="emit('secondary')"
                >
                    {{ secondaryLabel }}
                </Button>
            </div>

            <p
                v-if="footnote"
                class="mt-3 text-[12.5px] text-muted-foreground max-md:text-center"
            >
                {{ footnote }}
            </p>
        </DialogContent>
    </Dialog>
</template>
