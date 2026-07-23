<script setup lang="ts">
import type { DialogContentEmits, DialogContentProps } from "reka-ui"
import type { CSSProperties, HTMLAttributes } from "vue"
import { X } from "@lucide/vue"
import { reactiveOmit } from "@vueuse/core"
import {
  DialogClose,
  DialogContent,
  DialogPortal,
  injectDialogRootContext,
  useForwardPropsEmits,
} from "reka-ui"
import { computed } from "vue"
import { useIsMobile } from "@/composables/useIsMobile"
import { useKeyboardInset } from "@/composables/useKeyboardInset"
import { useSheetDrag } from "@/composables/useSheetDrag"
import { cn } from "@/lib/utils"
import DialogOverlay from "./DialogOverlay.vue"

/**
 * How a dialog presents below the `md` breakpoint.
 *
 * - `sheet` — a bottom sheet that grows with its content, capped so it never
 *   fills the screen. The default: a centred desktop dialog does not fit a
 *   phone, and making that every call site's problem is how they drifted apart.
 * - `detail` — a bottom sheet pinned to 85% of the screen, the mobile stand-in
 *   for a desktop right-hand pane (the epic's rules table).
 * - `dialog` — stay a centred dialog. For a surface that is already full-bleed,
 *   such as the image lightbox.
 * - `fullscreen` — the overlay is the screen: edge to edge, no sheet chrome.
 *   For the jump-to switcher, whose list deserves the whole viewport.
 */
type MobilePresentation = "sheet" | "detail" | "dialog" | "fullscreen"

/**
 * The height a sheet is allowed: tall enough to be worth opening, short enough
 * that the scrim above it still reads as the screen you came from.
 */
const SHEET_HEIGHT = "85dvh"

/**
 * The room kept under a sheet's last row — uniform whatever padding the call
 * site asked for, so a primary action never sits flush against the screen edge,
 * and clear of the home indicator on a device that has one.
 */
const SHEET_BOTTOM_PADDING = "1.5rem"

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(defineProps<DialogContentProps & {
  class?: HTMLAttributes["class"]
  showCloseButton?: boolean
  mobile?: MobilePresentation
}>(), {
  showCloseButton: true,
  mobile: "sheet",
})
const emits = defineEmits<DialogContentEmits>()

const delegatedProps = reactiveOmit(props, "class", "mobile")

const forwarded = useForwardPropsEmits(delegatedProps, emits)

const isMobile = useIsMobile()
const keyboardInset = useKeyboardInset()
const rootContext = injectDialogRootContext()

/** Whether this dialog is presenting as a bottom sheet at the current width. */
const asSheet = computed(() =>
  isMobile.value && props.mobile !== "dialog" && props.mobile !== "fullscreen")

/** Whether this dialog is presenting as a full-screen overlay at the current width. */
const asFullscreen = computed(() => isMobile.value && props.mobile === "fullscreen")

const drag = useSheetDrag({
  enabled: asSheet,
  onDismiss: () => rootContext.onOpenChange(false),
})

const contentClass = computed(() => {
  if (asFullscreen.value) {
    return cn(
      "bg-sidebar data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 flex w-full max-w-none flex-col overflow-hidden rounded-none border-0 p-0 duration-200",
      props.class,
    )
  }

  return asSheet.value
  ? cn(
      "bg-sidebar data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom fixed inset-x-0 bottom-0 z-50 flex w-full flex-col gap-4 overflow-y-auto rounded-t-[20px] border-t p-6 shadow-[0_-10px_32px_rgba(29,26,21,0.22)] transition-transform duration-200",
      props.class,
      // The handle takes the top of the sheet whatever padding the call site
      // set, so this trails those classes rather than leading them.
      "pt-1.5",
    )
  : cn(
      "bg-sidebar data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-[50%] left-[50%] z-50 grid w-full max-w-[calc(100%-2rem)] translate-x-[-50%] translate-y-[-50%] gap-4 rounded-2xl border p-6 shadow-[0_16px_40px_rgba(29,26,21,0.14)] duration-200 sm:max-w-lg",
      props.class,
    )
})

/**
 * What classes cannot say: a sheet has to beat whatever width the call site set
 * for its desktop dialog, and it sizes against the on-screen keyboard, which
 * only the visual viewport knows the height of.
 */
const contentStyle = computed<CSSProperties | undefined>(() => {
  if (asFullscreen.value) {
    // `inset-0` anchors to the layout viewport, which the on-screen keyboard
    // does not shrink — tracking it keeps the list's end reachable while typing.
    // Like the sheet, fullscreen has to beat any width the call site set for
    // its desktop dialog (`sm:` opens at 640px, below the breakpoint).
    return { bottom: `${keyboardInset.value}px`, maxWidth: "none" }
  }

  if (!asSheet.value) {
    return undefined
  }

  const room = `calc(${SHEET_HEIGHT} - ${keyboardInset.value}px)`

  return {
    maxWidth: "none",
    maxHeight: room,
    height: props.mobile === "detail" ? room : undefined,
    // `position: fixed` anchors to the layout viewport, which the keyboard does
    // not shrink — without this the sheet would open behind it.
    bottom: `${keyboardInset.value}px`,
    paddingBottom: `calc(${SHEET_BOTTOM_PADDING} + env(safe-area-inset-bottom))`,
    transform: drag.offset.value === 0 ? undefined : `translateY(${drag.offset.value}px)`,
    // The sheet follows the finger untweened; the tween is for the way back.
    transition: drag.dragging.value ? "none" : undefined,
  }
})
</script>

<template>
  <DialogPortal>
    <DialogOverlay />
    <DialogContent
      data-slot="dialog-content"
      v-bind="{ ...$attrs, ...forwarded }"
      :class="contentClass"
      :style="contentStyle"
    >
      <!-- The grab handle: a touch affordance for a gesture that Escape, the
           scrim and the close button each offer another way to, so it is
           decorative to a screen reader. -->
      <!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions -- a pointer-only drag target, not a control -->
      <div
        v-if="asSheet"
        aria-hidden="true"
        data-test="sheet-grab-handle"
        class="bg-inherit sticky top-0 z-10 flex shrink-0 cursor-grab touch-none justify-center py-1.5"
        @pointerdown="drag.start"
        @pointermove="drag.move"
        @pointerup="drag.end"
        @pointercancel="drag.cancel"
      >
        <span class="bg-muted-foreground/30 h-1 w-11 rounded-full" />
      </div>

      <slot />

      <DialogClose
        v-if="showCloseButton"
        data-slot="dialog-close"
        class="ring-offset-background focus:ring-ring data-[state=open]:bg-accent data-[state=open]:text-muted-foreground absolute top-4 right-4 rounded-xs opacity-70 transition-opacity hover:opacity-100 focus:ring-2 focus:ring-offset-2 focus:outline-hidden disabled:pointer-events-none [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"
      >
        <X />
        <span class="sr-only">{{ $t('Close') }}</span>
      </DialogClose>
    </DialogContent>
  </DialogPortal>
</template>
