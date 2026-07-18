<script setup lang="ts">
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useDemoMode } from '@/composables/useDemoMode';

/**
 * Wraps a destructive owner-level control so it renders disabled — with a
 * "Disabled in the demo" tooltip — while the instance is the public demo. Off
 * the demo it renders its slot untouched with no extra markup.
 *
 * The consumer binds the slot's `disabled` prop onto its own control (OR-ing in
 * any existing disabled state); a disabled trigger swallows pointer events, so
 * the tooltip is anchored on a wrapping span that still receives hover/focus.
 * This is UI convenience only — the server blocks every one of these actions
 * regardless (see PreventDestructiveDemoActions).
 */
// Forward fallthrough attributes (e.g. an `ml-auto` layout class) onto the span
// wrapper rather than the renderless TooltipProvider root, where Vue would drop
// them — so a caller can position the locked control in its flex row.
defineOptions({ inheritAttrs: false });

const { demoMode } = useDemoMode();
</script>

<template>
    <slot v-if="!demoMode" :disabled="false" />
    <TooltipProvider v-else :delay-duration="200">
        <Tooltip>
            <TooltipTrigger as-child>
                <!-- A disabled control is not focusable, so the span carries the
                     tooltip trigger and a tabindex to keep the "disabled in the
                     demo" reason reachable by keyboard, not just on hover. -->
                <span
                    v-bind="$attrs"
                    class="inline-flex cursor-not-allowed"
                    tabindex="0"
                    data-test="demo-lock"
                >
                    <slot :disabled="true" />
                </span>
            </TooltipTrigger>
            <TooltipContent>
                <p>{{ $t('Disabled in the demo') }}</p>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
