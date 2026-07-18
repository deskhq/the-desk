<script setup lang="ts">
import { AlarmClock, Clock, X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useInitials } from '@/composables/useInitials';
import { messageBodyPreview } from '@/lib/messageBody';
import { isReminderToday } from '@/lib/reminderTime';
import { formatScheduledFor } from '@/lib/scheduleTime';
import type { MessageReminder } from '@/types';

const props = defineProps<{
    reminders: MessageReminder[];
    timezone: string | null;
}>();

const emit = defineEmits<{
    /** Jump to the reminded message (and close the dialog). */
    open: [reminder: MessageReminder];
    /** Clear a single pending reminder. */
    clear: [id: string];
    /** Clear every pending reminder shown. */
    clearAll: [];
}>();

const open = defineModel<boolean>('open', { default: false });

const { getInitials } = useInitials();

const effectiveZone = computed(
    () => props.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone,
);

/**
 * The pending reminders arrive sorted soonest-first; split them so due-today
 * reminders sit above the rest, matching the design's "Today / Later" sections.
 */
const todayReminders = computed(() =>
    props.reminders.filter((reminder) =>
        isReminderToday(reminder.remindAt, effectiveZone.value),
    ),
);

const laterReminders = computed(() =>
    props.reminders.filter(
        (reminder) => !isReminderToday(reminder.remindAt, effectiveZone.value),
    ),
);

function whenLabel(iso: string): string {
    return formatScheduledFor(iso, effectiveZone.value);
}

function channelLabel(reminder: MessageReminder): string | null {
    return reminder.channelName ? `#${reminder.channelName}` : null;
}

function excerpt(reminder: MessageReminder): string {
    return reminder.isDeleted ? '' : messageBodyPreview(reminder.body);
}

function openReminder(reminder: MessageReminder): void {
    emit('open', reminder);
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="gap-4 sm:max-w-lg">
            <DialogHeader>
                <!-- Right padding leaves room for the dialog's own close button
                     so the "Clear all" action never sits under it. -->
                <div class="flex items-center gap-2.5 pr-7">
                    <DialogTitle class="flex items-center gap-2">
                        <AlarmClock class="size-4 text-brass-fill-foreground" />
                        {{ $t('Reminders') }}
                    </DialogTitle>
                    <span
                        v-if="reminders.length > 0"
                        data-test="reminders-count"
                        class="inline-flex h-5 items-center rounded-full bg-muted px-2 font-sans text-[11.5px] font-semibold text-muted-foreground"
                    >
                        {{
                            $t(':count pending', {
                                count: reminders.length,
                            })
                        }}
                    </span>
                    <Button
                        v-if="reminders.length > 0"
                        variant="unstyled"
                        size="none"
                        type="button"
                        data-test="reminders-clear-all"
                        class="ml-auto font-sans text-[12.5px] font-medium text-muted-foreground transition-colors hover:text-destructive"
                        @click="emit('clearAll')"
                    >
                        {{ $t('Clear all') }}
                    </Button>
                </div>
                <DialogDescription>
                    {{
                        $t(
                            'Messages you asked to be reminded about. Open one, or clear it.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="reminders.length > 0"
                class="max-h-96 space-y-4 overflow-y-auto"
                data-test="reminders-list"
            >
                <template
                    v-for="group in [
                        {
                            key: 'today',
                            label: $t('Today'),
                            items: todayReminders,
                        },
                        {
                            key: 'later',
                            label: $t('Later'),
                            items: laterReminders,
                        },
                    ]"
                    :key="group.key"
                >
                    <section v-if="group.items.length > 0" class="space-y-2">
                        <h3
                            class="px-1 text-[11px] font-semibold tracking-[0.08em] text-muted-foreground uppercase"
                        >
                            {{ group.label }}
                        </h3>
                        <div
                            v-for="reminder in group.items"
                            :key="reminder.id"
                            data-test="reminder-item"
                            :data-reminder="reminder.id"
                            class="flex items-center gap-3 rounded-xl border border-border bg-card p-3"
                        >
                            <Button
                                variant="unstyled"
                                size="none"
                                type="button"
                                data-test="reminder-open"
                                class="flex min-w-0 flex-1 items-center gap-3 text-left"
                                @click="openReminder(reminder)"
                            >
                                <div
                                    aria-hidden="true"
                                    class="flex size-8 shrink-0 items-center justify-center rounded-[10px] bg-primary/10 text-[10px] font-semibold text-primary"
                                >
                                    {{ getInitials(reminder.authorName) }}
                                </div>
                                <div class="flex min-w-0 flex-col gap-0.5">
                                    <div class="flex items-baseline gap-1.5">
                                        <span
                                            class="text-[13px] font-semibold text-foreground"
                                            >{{ reminder.authorName }}</span
                                        >
                                        <span
                                            v-if="channelLabel(reminder)"
                                            class="truncate text-[11px] text-muted-foreground"
                                            >{{
                                                $t('in :channel', {
                                                    channel:
                                                        channelLabel(
                                                            reminder,
                                                        ) ?? '',
                                                })
                                            }}</span
                                        >
                                    </div>
                                    <span
                                        v-if="reminder.isDeleted"
                                        class="truncate text-[13px] text-muted-foreground italic"
                                        >{{
                                            $t('This message was deleted.')
                                        }}</span
                                    >
                                    <span
                                        v-else
                                        class="truncate text-[13px] text-muted-foreground"
                                        >{{ excerpt(reminder) }}</span
                                    >
                                </div>
                            </Button>
                            <span
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brass-fill px-2.5 py-1 text-[11.5px] font-semibold text-brass-fill-foreground"
                                data-test="reminder-when"
                            >
                                <Clock class="size-3" />
                                {{ whenLabel(reminder.remindAt) }}
                            </span>
                            <Button
                                variant="unstyled"
                                size="none"
                                type="button"
                                data-test="reminder-clear"
                                :aria-label="$t('Clear reminder')"
                                class="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:text-destructive"
                                @click="emit('clear', reminder.id)"
                            >
                                <X class="size-4" />
                            </Button>
                        </div>
                    </section>
                </template>
            </div>

            <p
                v-else
                data-test="reminders-empty"
                class="py-6 text-center text-[13px] text-muted-foreground"
            >
                {{ $t('You have no reminders set.') }}
            </p>
        </DialogContent>
    </Dialog>
</template>
