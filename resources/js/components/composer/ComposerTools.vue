<script setup lang="ts">
import { Mic, Plus } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { FormatAction } from '@/composables/useComposerFormat';

defineProps<{
    /** The inline-format controls, each with its icon, label and shortcut hint. */
    formatActions: FormatAction[];
    /** Whether the composer knows its channel, which is what attachments need. */
    attachmentsEnabled: boolean;
    /** Whether this browser can record (MediaRecorder + a secure-context getUserMedia). */
    canRecord: boolean;
    /**
     * Whether the tools are disclosed. Only consulted below the breakpoint,
     * where they fold away behind a toggle so the field keeps the pill's width;
     * from `md` up they are always in line and this is ignored.
     */
    open: boolean;
}>();

defineEmits<{
    /** Wrap the current selection in a Markdown marker. */
    format: [marker: string];
    /** Open the native file picker. */
    attach: [];
    /** Open the mic. */
    record: [];
}>();
</script>

<template>
    <!-- One instance, placed by CSS: in line with the field from `md` up, on
         their own row under it once disclosed on a phone. -->
    <div
        class="shrink-0 items-end gap-2.5"
        :class="
            open
                ? 'order-last flex w-full pt-1 @lg:order-none @lg:w-auto @lg:pt-0'
                : 'hidden @lg:flex'
        "
        data-test="composer-tools"
    >
        <!-- Inline-format cluster: wraps the current selection in
             Markdown markers, mirrored by the keyboard shortcuts.
             mousedown is prevented so the textarea keeps focus and
             its selection survives the click. -->
        <TooltipProvider :delay-duration="300" :skip-delay-duration="150">
            <div
                class="flex shrink-0 items-center gap-0.5"
                data-test="composer-format-cluster"
            >
                <Tooltip v-for="action in formatActions" :key="action.marker">
                    <TooltipTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            :data-test="`message-composer-format-${action.marker}`"
                            class="size-7 shrink-0 rounded-full text-muted-foreground max-md:size-11"
                            :aria-label="action.label"
                            @mousedown.prevent
                            @click="$emit('format', action.marker)"
                        >
                            <component :is="action.icon" class="size-3.5" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent
                        side="top"
                        :side-offset="6"
                        class="flex items-center gap-2"
                    >
                        {{ action.label }}
                        <span
                            class="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                            >{{ action.shortcut }}</span
                        >
                    </TooltipContent>
                </Tooltip>
            </div>
        </TooltipProvider>
        <span
            class="mx-0.5 h-5 w-px shrink-0 self-center bg-border"
            aria-hidden="true"
        ></span>
        <Button
            variant="ghost"
            size="icon"
            :disabled="!attachmentsEnabled"
            data-test="message-composer-attach"
            class="size-7 shrink-0 rounded-full text-muted-foreground max-md:size-11"
            :aria-label="$t('Add attachment')"
            @click="$emit('attach')"
        >
            <Plus class="size-3.5" />
        </Button>
        <!-- The mic sits last before send, and only where the
             browser can actually record (MediaRecorder +
             getUserMedia in a secure context). -->
        <Button
            v-if="canRecord"
            variant="ghost"
            size="icon"
            data-test="message-composer-record"
            class="size-7 shrink-0 rounded-full text-muted-foreground max-md:size-11"
            :aria-label="$t('Record a voice message')"
            @click="$emit('record')"
        >
            <Mic class="size-3.5" />
        </Button>
    </div>
</template>
