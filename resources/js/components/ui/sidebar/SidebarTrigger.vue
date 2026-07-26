<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { PanelLeftClose, PanelLeftOpen } from "@lucide/vue"
import { computed } from "vue"
import { cn } from "@/lib/utils"
import { Button } from '@/components/ui/button'
import SidebarPanelIcon from "@/components/SidebarPanelIcon.vue"
import { useTranslations } from "@/composables/useTranslations"
import { useUnreadElsewhere } from "@/composables/useUnreadElsewhere"
import { formatUnreadCount } from "@/lib/unreadElsewhere"
import { useSidebar } from "./utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()

const { isMobile, state, toggleSidebar } = useSidebar()
const { t } = useTranslations()

const unread = useUnreadElsewhere()

// Below `md` the sidebar is a sheet, so every badge it carries is off screen
// while a conversation is open and the trigger has to report them; at `md` and
// up the sidebar is on screen and owns unread outright.
const showsUnread = computed(() => isMobile.value && unread.value.hasUnread)

const accessibleName = computed(() => {
  if (!showsUnread.value) {
    return t("Toggle sidebar")
  }

  return unread.value.count > 0
    ? t("Toggle sidebar, :count unread elsewhere", { count: unread.value.count })
    : t("Toggle sidebar, unread elsewhere")
})
</script>

<template>
  <Button
    data-sidebar="trigger"
    data-slot="sidebar-trigger"
    data-test="sidebar-toggle"
    variant="ghost"
    size="icon"
    :class="cn('relative h-7 w-7 max-md:min-h-11 max-md:min-w-11', props.class)"
    @click="toggleSidebar"
  >
    <SidebarPanelIcon v-if="isMobile" class="size-6" :unread="showsUnread" />
    <PanelLeftOpen v-else-if="state === 'collapsed'" />
    <PanelLeftClose v-else />
    <span
      v-if="showsUnread && unread.count > 0"
      data-test="sidebar-toggle-unread-count"
      aria-hidden="true"
      class="absolute top-1 right-1 flex h-4.25 min-w-4.25 items-center justify-center rounded-full bg-brass px-1 text-[10px] font-bold text-brass-foreground tabular-nums ring-2 ring-card"
      >{{ formatUnreadCount(unread.count) }}</span
    >
    <span class="sr-only">{{ accessibleName }}</span>
  </Button>
</template>
