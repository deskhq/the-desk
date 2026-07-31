<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import type { ComponentPublicInstance } from 'vue';
import { useTemplateRef } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import UserMenuContent from '@/components/UserMenuContent.vue';

/**
 * The "You" destination on desktop: the user menu as a popover anchored to the
 * rail's own avatar, leaving the panel beside it on whatever destination it was
 * already showing (design "Vous").
 *
 * The trigger is this component's default slot, exactly as the workspace sheet
 * takes its three anchors, so the rail keeps its own avatar markup and selector.
 *
 * The surface stays **uncontrolled**. Reka hands `close` out through the root's
 * slot, which is all the rows that leave the menu behind need, so there is no
 * reason to bind `:open` beside a trigger — the arrangement this repo has been
 * bitten by before (see the `Dialog` note on the workspace sheet).
 *
 * `modal` is deliberate: it is what puts the shell behind an `aria-hidden`
 * marker, which the app's own observer mirrors onto `inert` so the skip link
 * and the dock leave the tab order with it (#730).
 *
 * The card's height is whatever room the rail avatar leaves — Reka measures it
 * as `--reka-popover-content-available-height`, the same way the emoji picker
 * sizes itself. A fixed cap cannot do that job: `min(34rem, …)` froze the menu
 * at 544px on every viewport taller than that, so its rows scrolled with screen
 * to spare above and below the card (#998).
 */
const page = usePage();

const trigger = useTemplateRef<ComponentPublicInstance>('trigger');

/**
 * Hand focus back to the rail avatar as the menu closes.
 *
 * A modal surface puts the shell behind `aria-hidden`, which the app mirrors
 * onto `inert` (#730) — and that covers the trigger too. Reka's own restore
 * runs while the attribute is still up, so it lands on `<body>`; taking the
 * step over ourselves, after the layer is gone, keeps the keyboard where it
 * started (#935).
 */
function restoreFocusToTrigger(event: Event): void {
    event.preventDefault();

    const element = trigger.value?.$el;

    if (!(element instanceof HTMLElement)) {
        return;
    }

    // The next frame, not this tick: the marker is lifted by a MutationObserver
    // callback, and focusing a still-inert element is silently a no-op.
    requestAnimationFrame(() => element.focus());
}
</script>

<template>
    <Popover v-slot="{ close }" modal>
        <PopoverTrigger ref="trigger" as-child>
            <slot />
        </PopoverTrigger>
        <PopoverContent
            data-test="user-menu-popover"
            side="right"
            align="end"
            :side-offset="8"
            :collision-padding="12"
            @close-auto-focus="restoreFocusToTrigger"
            class="flex max-h-[var(--reka-popover-content-available-height)] w-61.5 flex-col gap-0 rounded-[14px] border-border bg-popover p-0 shadow-[0_18px_40px_rgba(60,55,40,0.18)] dark:shadow-[0_18px_40px_rgba(0,0,0,0.5)]"
        >
            <UserMenuContent
                :user="page.props.auth.user"
                variant="popover"
                @dismiss="close"
            />
        </PopoverContent>
    </Popover>
</template>
