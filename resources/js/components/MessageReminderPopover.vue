<script setup lang="ts">
import { AlarmClock, CalendarClock } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, ref } from 'vue';
import { reminderPresets } from '@/lib/reminderTime';

const props = defineProps<{
    // The viewer's stored IANA zone; falls back to the runtime zone when null so
    // the wall-clock presets ("Tomorrow 9am") resolve in a real zone.
    timezone: string | null;
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
        <PopoverTrigger as-child>
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
                    <button
                        v-for="preset in presets"
                        :key="preset.key"
                        type="button"
                        data-test="reminder-preset"
                        :data-preset="preset.key"
                        class="flex h-9 items-center justify-between gap-2 rounded-lg px-3 text-[13.5px] font-medium text-foreground transition-colors hover:bg-accent"
                        @click="choose(preset.remindAt)"
                    >
                        <span>{{ $t(preset.label) }}</span>
                        <span
                            v-if="preset.detail"
                            class="text-[12px] font-normal text-muted-foreground"
                            >{{ preset.detail }}</span
                        >
                    </button>
                    <div class="mx-2 my-1.5 h-px bg-border"></div>
                    <button
                        type="button"
                        data-test="reminder-custom"
                        class="flex h-9 items-center gap-2 rounded-lg px-3 text-[13.5px] font-medium text-brass-fill-foreground transition-colors hover:bg-accent"
                        @click="chooseCustom"
                    >
                        <CalendarClock class="size-3.5" />
                        {{ $t('Custom date & time…') }}
                    </button>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
