<script lang="ts" setup>
import type { ToasterProps } from "vue-sonner"
import { Toaster as Sonner } from "vue-sonner"
import { cn } from "@/lib/utils"

import 'vue-sonner/style.css';

// Toasts sit in the lower zone of the bottom-right rail, nearest the action that
// raised them and clear of the masthead. This reverses #430, which moved them to
// top-center to stop them covering the composer: the rail sits *above* the
// composer, so it solves that without landing on the channel name and its
// actions instead. See #978.
//
// Every toast renders through `<ToastCard>` via `toast.custom`, so the slab, the
// glyph disc and the drain hairline are ours; sonner keeps the positioning,
// stacking, swipe-to-dismiss, live regions and the hover/focus timer pause —
// the things easiest to get subtly wrong and hardest to catch in tests. None of
// its own six tone icons or surface variables are reachable any more, so they
// are gone rather than left to rot.
//
// `--offset-right` is the rail's, published by whatever pane owns the inset (see
// `MainLayout`), so the toast never straddles the thread panel.
const props = withDefaults(defineProps<ToasterProps>(), {
  position: "bottom-right",
  visibleToasts: 3,
  swipeDirections: () => ["bottom", "right"],
})
</script>

<template>
  <Sonner
    :class="cn('toaster group', props.class)"
    :style="{
      '--width': 'auto',
    }"
    v-bind="props"
  />
</template>
