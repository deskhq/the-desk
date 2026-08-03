<script setup lang="ts">
import type { ListboxContentProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { ListboxContent, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<ListboxContentProps & {
  class?: HTMLAttributes["class"]
  /**
   * Accessible name for the rendered `role="listbox"` element, required at the
   * type level: an unnamed ARIA input field is a serious axe violation
   * (`aria-input-field-name`, #798), so every call site must say what its list
   * contains rather than rely on remembering to pass a loose attribute.
   */
  ariaLabel: string
}>()

const delegatedProps = reactiveOmit(props, "class", "ariaLabel")

const forwarded = useForwardProps(delegatedProps)

defineOptions({
  // The scrolling wrapper below is this component's own affair, so a caller's
  // attributes go where a caller means them: the `role="listbox"` element they
  // name and point their `aria-controls` at.
  inheritAttrs: false,
})
</script>

<template>
  <!--
    The scroll sits on a wrapper rather than on the listbox itself, and the
    wrapper is a tab stop. Every row is an option (`tabindex="-1"`) and reka
    keeps the listbox at -1 too while one of them is highlighted, so a list long
    enough to scroll would hold no keyboard-reachable thing at all — a serious
    axe failure (`scrollable-region-focusable`) and a real one on a phone, where
    the list is the screen. Picking and filtering are unchanged: they run from
    the filter field through `aria-activedescendant`.
  -->
  <div
    data-slot="command-list-viewport"
    tabindex="0"
    :class="cn('max-h-[300px] scroll-py-1 overflow-x-hidden overflow-y-auto', props.class)"
  >
    <ListboxContent
      data-slot="command-list"
      v-bind="{ ...forwarded, ...$attrs }"
      :aria-label="props.ariaLabel"
    >
      <div role="presentation">
        <slot />
      </div>
    </ListboxContent>
  </div>
</template>
