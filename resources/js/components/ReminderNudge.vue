<script setup lang="ts">
import { AlarmClock, X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/composables/useInitials';
import { messageBodyPreview } from '@/lib/messageBody';
import type { MessageReminder } from '@/types';

const props = defineProps<{
    reminder: MessageReminder;
}>();

const emit = defineEmits<{
    // Jump to the reminded message and acknowledge the nudge.
    open: [reminder: MessageReminder];
    // Push the reminder out by 20 minutes.
    snooze: [reminder: MessageReminder];
    // Acknowledge and clear the nudge without jumping.
    dismiss: [reminder: MessageReminder];
}>();

const { getInitials } = useInitials();

// A one-line preview of the reminded message, or a stub when it was deleted.
const excerpt = computed(() => messageBodyPreview(props.reminder.body));

// "#design" for a channel, blank for a direct message (no channel name).
const channelLabel = computed(() =>
    props.reminder.channelName ? `#${props.reminder.channelName}` : null,
);
</script>

<template>
    <div
        data-test="reminder-nudge"
        :data-reminder="reminder.id"
        class="pointer-events-auto flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-3 rounded-2xl bg-primary p-4 text-primary-foreground shadow-[0_20px_48px_rgba(29,26,21,0.4)]"
    >
        <div class="flex items-center gap-2">
            <AlarmClock class="size-3.5 text-brass" />
            <span
                class="text-[11px] font-semibold tracking-[0.08em] text-brass uppercase"
            >
                {{ $t('Reminder · due now') }}
            </span>
            <Button
                variant="unstyled"
                size="none"
                type="button"
                data-test="reminder-nudge-close"
                :aria-label="$t('Dismiss reminder')"
                class="ml-auto rounded p-0.5 text-primary-foreground/50 transition-colors hover:text-primary-foreground"
                @click="emit('dismiss', reminder)"
            >
                <X class="size-3.5" />
            </Button>
        </div>

        <div
            class="flex gap-2.5 rounded-xl border border-primary-foreground/10 bg-primary-foreground/5 px-3 py-2.5"
        >
            <div
                aria-hidden="true"
                class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-primary-foreground/15 text-[10px] font-semibold"
            >
                {{ getInitials(reminder.authorName) }}
            </div>
            <div class="flex min-w-0 flex-col gap-0.5">
                <div class="flex items-baseline gap-1.5">
                    <span class="text-[13px] font-semibold">{{
                        reminder.authorName
                    }}</span>
                    <span
                        v-if="channelLabel"
                        class="truncate text-[11px] text-primary-foreground/50"
                        >{{
                            $t('in :channel', { channel: channelLabel })
                        }}</span
                    >
                </div>
                <span
                    v-if="reminder.isDeleted"
                    class="text-[13px] text-primary-foreground/50 italic"
                    >{{ $t('This message was deleted.') }}</span
                >
                <span
                    v-else
                    class="truncate text-[13px] text-primary-foreground/70"
                    >{{ excerpt }}</span
                >
            </div>
        </div>

        <div class="flex items-center gap-2.5">
            <Button
                variant="unstyled"
                size="none"
                type="button"
                data-test="reminder-nudge-open"
                class="inline-flex h-8 items-center rounded-full bg-brass px-4 text-[12.5px] font-semibold text-brass-foreground transition-opacity hover:opacity-90"
                @click="emit('open', reminder)"
            >
                {{ $t('Open message') }}
            </Button>
            <Button
                variant="unstyled"
                size="none"
                type="button"
                data-test="reminder-nudge-snooze"
                class="inline-flex h-8 items-center rounded-full border border-primary-foreground/25 px-3.5 text-[12.5px] font-medium text-primary-foreground/80 transition-colors hover:bg-primary-foreground/10"
                @click="emit('snooze', reminder)"
            >
                {{ $t('Snooze 20 min') }}
            </Button>
            <Button
                variant="unstyled"
                size="none"
                type="button"
                data-test="reminder-nudge-done"
                class="ml-auto text-[12.5px] font-medium text-primary-foreground/60 transition-colors hover:text-primary-foreground"
                @click="emit('dismiss', reminder)"
            >
                {{ $t('Done') }}
            </Button>
        </div>
    </div>
</template>
