<script setup lang="ts">
import type { HTMLAttributes, Ref } from "vue"
import { router } from "@inertiajs/vue3"
import { defaultDocument, useEventListener, useVModel } from "@vueuse/core"
import { TooltipProvider } from "reka-ui"
import { computed, onUnmounted, ref } from "vue"
import { useEdgeSwipe } from "@/composables/useEdgeSwipe"
import { useIsMobile } from "@/composables/useIsMobile"
import { useSidebarPosition } from "@/composables/useSidebarPosition"
import { writeClientCookie } from "@/lib/cookies"
import { cn } from "@/lib/utils"
import { provideSidebarContext, SIDEBAR_COOKIE_MAX_AGE, SIDEBAR_COOKIE_NAME, SIDEBAR_KEYBOARD_SHORTCUT, SIDEBAR_WIDTH, SIDEBAR_WIDTH_ICON } from "./utils"

const props = withDefaults(defineProps<{
  defaultOpen?: boolean
  open?: boolean
  class?: HTMLAttributes["class"]
}>(), {
  defaultOpen: !defaultDocument?.cookie.includes(`${SIDEBAR_COOKIE_NAME}=false`),
  open: undefined,
})

const emits = defineEmits<{
  "update:open": [open: boolean]
}>()

const isMobile = useIsMobile()
const openMobile = ref(false)

const open = useVModel(props, "open", emits, {
  defaultValue: props.defaultOpen ?? false,
  passive: (props.open === undefined) as false,
}) as Ref<boolean>

function setOpen(value: boolean) {
  open.value = value // emits('update:open', value)

  // This sets the cookie to keep the sidebar state.
  writeClientCookie(SIDEBAR_COOKIE_NAME, String(open.value), SIDEBAR_COOKIE_MAX_AGE)
}

function setOpenMobile(value: boolean) {
  openMobile.value = value
}

function toggleSidebar() {
  return isMobile.value ? setOpenMobile(!openMobile.value) : setOpen(!open.value)
}

// Below the breakpoint the dock is a Sheet, so it also answers to the gesture
// every phone app uses for one: swipe in from its own edge to open, back out to
// dismiss. The Sheet slides from whichever edge the user docked it to, so the
// gesture follows that preference rather than assuming the left.
const { sidebarPosition } = useSidebarPosition()

useEdgeSwipe({
  enabled: isMobile,
  edge: sidebarPosition,
  onOpen: () => setOpenMobile(true),
  onClose: () => setOpenMobile(false),
})

// Navigate = close (#834): landing somewhere new is the dock's job done, so
// the full-screen sheet dismisses itself rather than sitting on top of the
// destination. Inertia fires `navigate` for URL-changing visits and history
// traversal, but not for same-URL visits or partial reloads (section collapse,
// background `router.reload`s) — those replace the current history entry and
// skip the event, which is exactly the "non-navigating interactions keep the
// dock open" rule.
const stopNavigateListener = router.on("navigate", () => setOpenMobile(false))

onUnmounted(stopNavigateListener)

useEventListener("keydown", (event: KeyboardEvent) => {
  if (event.key === SIDEBAR_KEYBOARD_SHORTCUT && (event.metaKey || event.ctrlKey)) {
    event.preventDefault()
    toggleSidebar()
  }
})

/**
 * We add a state so that we can do data-state="expanded" or "collapsed".
 * This makes it easier to style the sidebar with Tailwind classes.
 */
const state = computed(() => open.value ? "expanded" : "collapsed")

provideSidebarContext({
  state,
  open,
  setOpen,
  isMobile,
  openMobile,
  setOpenMobile,
  toggleSidebar,
})
</script>

<template>
  <TooltipProvider :delay-duration="0">
    <div
      data-slot="sidebar-wrapper"
      :style="{
        '--sidebar-width': SIDEBAR_WIDTH,
        '--sidebar-width-icon': SIDEBAR_WIDTH_ICON,
      }"
      :class="cn('group/sidebar-wrapper has-data-[variant=inset]:bg-sidebar flex min-h-svh w-full', props.class)"
      v-bind="$attrs"
    >
      <slot />
    </div>
  </TooltipProvider>
</template>
