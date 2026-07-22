<script setup lang="ts">
import type { DropdownMenuItemProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { DropdownMenuItem, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = withDefaults(defineProps<DropdownMenuItemProps & {
  class?: HTMLAttributes["class"]
  inset?: boolean
  variant?: "default" | "destructive"
}>(), {
  variant: "default",
})

const delegatedProps = reactiveOmit(props, "inset", "variant", "class")

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <!-- The destructive variant paints --destructive-text rather than the
       --destructive fill (3.93:1 as menu text on the dark popover, #717), and
       keeps the /10 focus tint in both themes: shadcn's dark-mode bump to /20
       lightens the fill enough to pull that text back under AA. -->
  <DropdownMenuItem
    data-slot="dropdown-menu-item"
    :data-inset="inset ? '' : undefined"
    :data-variant="variant"
    v-bind="forwardedProps"
    :class="cn('focus:bg-accent focus:text-accent-foreground data-[variant=destructive]:text-destructive-text data-[variant=destructive]:focus:bg-destructive/10 data-[variant=destructive]:focus:text-destructive-text data-[variant=destructive]:*:[svg]:!text-destructive-text [&_svg:not([class*=\'text-\'])]:text-muted-foreground relative flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-hidden select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 data-[inset]:pl-8 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*=\'size-\'])]:size-4', props.class)"
  >
    <slot />
  </DropdownMenuItem>
</template>
