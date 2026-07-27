<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineProps<{
    /**
     * The section's stable key, spelled into the `data-test` selector browser
     * tests reach the toggle by (`section-toggle-starred` and friends).
     */
    name: string;
    /** The header's visible label, already translated by the caller. */
    label: string;
    /** Whether the group below is currently collapsed. */
    collapsed: boolean;
}>();

defineEmits<{
    /** The user asked to collapse or expand the group. */
    toggle: [];
}>();
</script>

<template>
    <Button
        variant="ghost"
        :data-test="`section-toggle-${name}`"
        :aria-expanded="!collapsed"
        class="flex h-7 w-full items-center justify-start gap-1 rounded-md px-2 text-[10.5px] font-semibold tracking-[0.1em] text-muted-foreground uppercase transition-colors hover:bg-sidebar-accent/40 hover:text-sidebar-foreground"
        @click="$emit('toggle')"
    >
        <ChevronRight
            class="size-3 shrink-0 transition-transform"
            :class="collapsed ? '' : 'rotate-90'"
        />
        {{ label }}
    </Button>
</template>
