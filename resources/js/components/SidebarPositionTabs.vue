<script setup lang="ts">
import { Check } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useSidebarPosition } from '@/composables/useSidebarPosition';
import type { SidebarPositionOption } from '@/types';

defineProps<{
    options: SidebarPositionOption[];
}>();

const { sidebarPosition, updateSidebarPosition } = useSidebarPosition();
</script>

<template>
    <!--
      Sidebar position is picked via miniature preview swatches, mirroring the
      Theme picker above. Each swatch depicts the live workspace — the dock on
      the chosen edge, the main pane on the other — using the active-theme tokens
      so it reads correctly in both light and dark.
    -->
    <div class="grid max-w-[428px] grid-cols-2 gap-3">
        <Button
            v-for="{ value, label } in options"
            :key="value"
            variant="unstyled"
            size="none"
            type="button"
            :aria-pressed="sidebarPosition === value"
            :data-test="`sidebar-position-${value}`"
            @click="updateSidebarPosition(value)"
            class="flex flex-col gap-2 rounded-[13px] text-left"
        >
            <div
                class="overflow-hidden rounded-xl border-2 transition-colors"
                :class="
                    sidebarPosition === value
                        ? 'border-brass shadow-[0_2px_8px_rgba(60,55,40,0.1)]'
                        : 'border-border'
                "
            >
                <div
                    class="flex h-21 gap-1.5 bg-background p-2.5"
                    :class="value === 'right' ? 'flex-row-reverse' : ''"
                >
                    <!-- Dock: sits on the chosen edge; its top bar lights brass
                         when this option is the active one. -->
                    <div
                        class="flex w-[26%] flex-col gap-1 rounded-md bg-sidebar p-1.5"
                    >
                        <div
                            class="h-1.5 w-[70%] rounded-full"
                            :class="
                                sidebarPosition === value
                                    ? 'bg-brass'
                                    : 'bg-sidebar-foreground/25'
                            "
                        />
                        <div
                            class="h-1.5 w-[85%] rounded-full bg-sidebar-foreground/25"
                        />
                        <div
                            class="h-1.5 w-[60%] rounded-full bg-sidebar-foreground/25"
                        />
                    </div>

                    <!-- Main pane -->
                    <div
                        class="flex flex-1 flex-col gap-1 rounded-md border border-border bg-card p-1.75"
                    >
                        <div
                            class="h-1.5 w-[55%] rounded-full bg-muted-foreground/25"
                        />
                        <div
                            class="h-1.5 w-[80%] rounded-full bg-muted-foreground/15"
                        />
                        <div
                            class="h-1.5 w-[68%] rounded-full bg-muted-foreground/15"
                        />
                    </div>
                </div>
            </div>

            <span
                class="flex items-center gap-1.5 text-[13px] font-semibold"
                :class="
                    sidebarPosition === value
                        ? 'text-foreground'
                        : 'text-muted-foreground'
                "
            >
                <Check
                    v-if="sidebarPosition === value"
                    class="size-3.5 text-brass"
                    :stroke-width="2.5"
                />
                <span v-else class="size-3.5" aria-hidden="true" />
                {{ label }}
            </span>
        </Button>
    </div>
</template>
