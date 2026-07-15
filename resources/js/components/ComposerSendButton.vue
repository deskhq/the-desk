<script setup lang="ts">
import { CalendarDays, ChevronUp, Send } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatPresetPreview, schedulePresets } from '@/lib/scheduleTime';

const props = defineProps<{
    // Whether the composer has something to send right now (text or a ready
    // attachment). Gates the primary Send and the "Send now" menu item.
    canSubmit: boolean;
    // Whether the composer body carries text to schedule. Scheduling never
    // includes attachments, so it gates on text alone — matching the old
    // schedule button's "empty composer has nothing to schedule" guard.
    canSchedule: boolean;
    // The viewer's stored IANA zone; drives the quick presets' resolved times.
    timezone: string | null;
}>();

const emit = defineEmits<{
    // Deliver the composer body now.
    send: [];
    // Schedule the body for the given UTC instant (a quick preset was chosen).
    scheduleAt: [sendAt: string];
    // Open the full schedule dialog to pick a custom time.
    customTime: [];
}>();

const menuOpen = ref(false);

const effectiveZone = computed(
    () => props.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone,
);

/**
 * The three quick presets the mockup surfaces in the menu, keyed to the shared
 * preset math so the resolved instant matches the schedule dialog exactly. Menu
 * labels differ from the dialog's ("Tomorrow morning" vs "Tomorrow 9 AM"), so
 * they are named here rather than read off the shared preset.
 */
const QUICK_PRESETS = [
    { key: 'in-an-hour', label: 'In 1 hour' },
    { key: 'tomorrow-morning', label: 'Tomorrow morning' },
    { key: 'next-monday', label: 'Monday morning' },
] as const;

type QuickPreset = {
    key: string;
    label: string;
    sendAt: string;
    preview: string;
};

// Resolved on open so the times stay current with the wall clock each time the
// menu is shown, rather than frozen at mount.
const quickPresets = ref<QuickPreset[]>([]);

function buildPresets(): void {
    const now = new Date();
    const zone = effectiveZone.value;
    const all = schedulePresets(zone, now);

    quickPresets.value = QUICK_PRESETS.flatMap(({ key, label }) => {
        const preset = all.find((candidate) => candidate.key === key);

        return preset
            ? [
                  {
                      key,
                      label,
                      sendAt: preset.sendAt,
                      preview: formatPresetPreview(preset.sendAt, zone, now),
                  },
              ]
            : [];
    });
}

watch(menuOpen, (isOpen) => {
    if (isOpen) {
        buildPresets();
    }
});

function choosePreset(preset: QuickPreset): void {
    emit('scheduleAt', preset.sendAt);
}
</script>

<template>
    <div
        class="flex items-stretch overflow-hidden rounded-full bg-primary shadow-[0_2px_6px_rgba(29,26,21,0.25)]"
    >
        <Button
            variant="unstyled"
            size="none"
            type="button"
            data-test="message-composer-send"
            :disabled="!canSubmit"
            :aria-label="$t('Send message')"
            class="flex h-8.5 shrink-0 items-center gap-1.5 pr-3 pl-4 text-[13px] font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/5 disabled:opacity-40"
            @click="emit('send')"
        >
            {{ $t('Send') }}
            <Send class="size-3.25" />
        </Button>

        <span
            class="w-px shrink-0 bg-primary-foreground/20"
            aria-hidden="true"
        ></span>

        <DropdownMenu v-model:open="menuOpen">
            <DropdownMenuTrigger as-child>
                <Button
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="message-composer-schedule"
                    :aria-label="$t('Send later')"
                    class="flex h-8.5 w-8 shrink-0 items-center justify-center bg-primary-foreground/[0.08] text-primary-foreground transition-colors hover:bg-primary-foreground/15"
                >
                    <ChevronUp class="size-3.25" />
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                align="end"
                :side-offset="8"
                data-test="send-later-menu"
                class="w-64 rounded-xl p-1.5 shadow-lg"
            >
                <DropdownMenuItem
                    data-test="send-now"
                    :disabled="!canSubmit"
                    class="flex items-center justify-between rounded-lg px-2.5 py-2 text-[13px] font-semibold"
                    @select="emit('send')"
                >
                    {{ $t('Send now') }}
                    <span
                        class="font-mono text-[10.5px] font-semibold text-muted-foreground"
                        aria-hidden="true"
                        >⏎</span
                    >
                </DropdownMenuItem>

                <template v-if="canSchedule">
                    <DropdownMenuSeparator class="mx-1 bg-border" />
                    <DropdownMenuLabel
                        class="px-2.5 py-1 text-[10.5px] font-semibold tracking-[0.08em] text-muted-foreground uppercase"
                    >
                        {{ $t('Send later') }}
                    </DropdownMenuLabel>

                    <DropdownMenuItem
                        v-for="preset in quickPresets"
                        :key="preset.key"
                        data-test="send-later-preset"
                        :data-preset="preset.key"
                        class="flex items-center justify-between rounded-lg px-2.5 py-2 text-[13px]"
                        @select="choosePreset(preset)"
                    >
                        <span>{{ $t(preset.label) }}</span>
                        <span class="text-[12px] text-muted-foreground">{{
                            preset.preview
                        }}</span>
                    </DropdownMenuItem>

                    <DropdownMenuSeparator class="mx-1 bg-border" />
                    <DropdownMenuItem
                        data-test="send-later-custom"
                        class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-[13px]"
                        @select="emit('customTime')"
                    >
                        <CalendarDays class="size-3.5 text-muted-foreground" />
                        {{ $t('Custom time…') }}
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
