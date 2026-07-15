<script setup lang="ts">
import { AlarmClock, CalendarClock } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { reminderPresets } from '@/lib/reminderTime';

const props = defineProps<{
    // The viewer's stored IANA zone; falls back to the runtime zone when null so
    // the wall-clock presets ("Tomorrow 9am") resolve in a real zone.
    timezone: string | null;
    // Optional label shown in a tooltip above the trigger on hover and keyboard
    // focus. When set, the trigger is composed as Tooltip → PopoverTrigger so the
    // one button anchors both the popover (on click) and the tooltip (on
    // hover/focus); this requires a TooltipProvider ancestor.
    tooltip?: string;
}>();

const emit = defineEmits<{
    // A preset was chosen: the resolved UTC instant to remind at.
    set: [remindAt: string];
    // The viewer wants to pick an exact date & time instead.
    custom: [];
}>();

const open = ref(false);

const effectiveZone = computed(
    () => props.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone,
);

// Recomputed each time the popover opens so "In 20 minutes" is always measured
// from now, never from when the row first rendered.
const presets = ref(reminderPresets(effectiveZone.value));

function onOpenChange(next: boolean): void {
    if (next) {
        presets.value = reminderPresets(effectiveZone.value);
    }

    open.value = next;
}

function choose(remindAt: string): void {
    emit('set', remindAt);
    open.value = false;
}

function chooseCustom(): void {
    emit('custom');
    open.value = false;
}
</script>

<template>
    <PopoverRoot :open="open" @update:open="onOpenChange">
        <Tooltip v-if="props.tooltip">
            <TooltipTrigger as-child>
                <PopoverTrigger as-child>
                    <slot :open="open" />
                </PopoverTrigger>
            </TooltipTrigger>
            <TooltipContent side="top" :side-offset="6">
                {{ props.tooltip }}
            </TooltipContent>
        </Tooltip>
        <PopoverTrigger v-else as-child>
            <slot :open="open" />
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="end"
                :side-offset="6"
                :collision-padding="8"
                data-test="reminder-popover"
                class="z-50 w-64 rounded-xl border border-border bg-popover p-2 text-popover-foreground shadow-[0_16px_40px_rgba(29,26,21,0.18)] outline-none"
            >
                <div
                    class="flex items-center gap-2 px-3 pt-1.5 pb-1 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    <AlarmClock class="size-3.5 text-brass" />
                    {{ $t('Remind me about this') }}
                </div>
                <div class="flex flex-col">
                    <Button
                        v-for="preset in presets"
                        :key="preset.key"
                        variant="ghost"
                        size="none"
                        type="button"
                        data-test="reminder-preset"
                        :data-preset="preset.key"
                        class="flex h-9 items-center justify-between gap-2 rounded-lg px-3 text-[13.5px] font-medium text-foreground"
                        @click="choose(preset.remindAt)"
                    >
                        <span>{{ $t(preset.label) }}</span>
                        <span
                            v-if="preset.detail"
                            class="text-[12px] font-normal text-muted-foreground"
                            >{{ preset.detail }}</span
                        >
                    </Button>
                    <div class="mx-2 my-1.5 h-px bg-border"></div>
                    <Button
                        variant="ghost"
                        size="none"
                        type="button"
                        data-test="reminder-custom"
                        class="flex h-9 items-center gap-2 rounded-lg px-3 text-[13.5px] font-medium text-brass-fill-foreground"
                        @click="chooseCustom"
                    >
                        <CalendarClock class="size-3.5" />
                        {{ $t('Custom date & time…') }}
                    </Button>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
