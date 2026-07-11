<script setup lang="ts">
import { CalendarClock, Clock, Pencil, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import MessageQuote from '@/components/MessageQuote.vue';
import ScheduleMessageDialog from '@/components/ScheduleMessageDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { messageBodyPreview } from '@/lib/messageBody';
import { formatScheduledFor } from '@/lib/scheduleTime';
import type { ScheduledMessage } from '@/types';

const props = defineProps<{
    scheduledMessages: ScheduledMessage[];
    timezone: string | null;
}>();

const emit = defineEmits<{
    update: [payload: { id: string; body: string; sendAt: string }];
    cancel: [id: string];
}>();

const open = defineModel<boolean>('open', { default: false });

// The row being edited, plus its working copy of the body and send time. Null
// when the list is just being browsed.
const editingId = ref<string | null>(null);
const editBody = ref('');
const editSendAt = ref('');
const rescheduling = ref(false);

// Closing the dialog abandons any in-progress edit so it never reopens stale.
watch(open, (isOpen) => {
    if (!isOpen) {
        editingId.value = null;
    }
});

// If the row being edited is delivered or cancelled out from under us (the prop
// refreshes after a dispatch), drop back to the list.
watch(
    () => props.scheduledMessages,
    (messages) => {
        if (
            editingId.value !== null &&
            !messages.some((message) => message.id === editingId.value)
        ) {
            editingId.value = null;
        }
    },
);

function startEdit(scheduled: ScheduledMessage): void {
    editingId.value = scheduled.id;
    editBody.value = scheduled.body;
    editSendAt.value = scheduled.sendAt;
}

function cancelEdit(): void {
    editingId.value = null;
}

function onRescheduled(sendAt: string): void {
    editSendAt.value = sendAt;
}

const canSave = computed(() => editBody.value.trim() !== '');

function saveEdit(): void {
    if (editingId.value === null || !canSave.value) {
        return;
    }

    emit('update', {
        id: editingId.value,
        body: editBody.value.trim(),
        sendAt: editSendAt.value,
    });
    editingId.value = null;
}

function cancelSend(scheduled: ScheduledMessage): void {
    emit('cancel', scheduled.id);
}

function whenLabel(iso: string): string {
    return formatScheduledFor(
        iso,
        props.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone,
    );
}

function bodyPreview(body: string): string {
    return messageBodyPreview(body);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="gap-4 sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ $t('Scheduled messages') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            "Messages waiting to be sent to this channel. Edit or cancel any that haven't gone out yet.",
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <ul
                v-if="props.scheduledMessages.length > 0"
                class="max-h-96 space-y-2 overflow-y-auto"
                data-test="scheduled-list"
            >
                <li
                    v-for="scheduled in props.scheduledMessages"
                    :key="scheduled.id"
                    data-test="scheduled-item"
                    class="rounded-lg border border-border p-3"
                >
                    <template v-if="editingId === scheduled.id">
                        <textarea
                            v-model="editBody"
                            rows="2"
                            data-test="scheduled-edit-body"
                            class="w-full resize-none rounded-md border border-input bg-background px-2.5 py-1.5 text-sm leading-[1.5] text-foreground outline-none focus:border-ring focus:ring-1 focus:ring-ring"
                        ></textarea>
                        <div
                            class="mt-2 flex items-center justify-between gap-2"
                        >
                            <button
                                type="button"
                                data-test="scheduled-reschedule"
                                class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[13px] text-muted-foreground hover:bg-muted hover:text-foreground"
                                @click="rescheduling = true"
                            >
                                <CalendarClock class="size-3.5" />
                                {{ whenLabel(editSendAt) }}
                            </button>
                            <div class="flex items-center gap-1.5">
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    class="h-7"
                                    @click="cancelEdit"
                                >
                                    {{ $t('Discard') }}
                                </Button>
                                <Button
                                    size="sm"
                                    class="h-7"
                                    data-test="scheduled-save"
                                    :disabled="!canSave"
                                    @click="saveEdit"
                                >
                                    {{ $t('Save') }}
                                </Button>
                            </div>
                        </div>
                    </template>

                    <template v-else>
                        <MessageQuote
                            v-if="scheduled.replyTo"
                            :author-name="scheduled.replyTo.authorName"
                            :body="scheduled.replyTo.body"
                            :is-deleted="scheduled.replyTo.isDeleted"
                            class="mb-1"
                        />
                        <p class="line-clamp-3 text-sm text-foreground">
                            {{ bodyPreview(scheduled.body) }}
                        </p>
                        <div
                            class="mt-1.5 flex items-center justify-between gap-2"
                        >
                            <span
                                class="inline-flex items-center gap-1.5 text-[12.5px] text-muted-foreground"
                                data-test="scheduled-when"
                            >
                                <Clock class="size-3.5" />
                                {{ whenLabel(scheduled.sendAt) }}
                            </span>
                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    :aria-label="$t('Edit scheduled message')"
                                    data-test="scheduled-edit"
                                    class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                                    @click="startEdit(scheduled)"
                                >
                                    <Pencil class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    :aria-label="$t('Cancel scheduled message')"
                                    data-test="scheduled-cancel"
                                    class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive"
                                    @click="cancelSend(scheduled)"
                                >
                                    <Trash2 class="size-4" />
                                </button>
                            </div>
                        </div>
                    </template>
                </li>
            </ul>

            <p
                v-else
                data-test="scheduled-empty"
                class="py-6 text-center text-[13px] text-muted-foreground"
            >
                {{ $t('You have no messages scheduled for this channel.') }}
            </p>
        </DialogContent>
    </Dialog>

    <ScheduleMessageDialog
        v-model:open="rescheduling"
        :timezone="props.timezone"
        :initial-send-at="editSendAt"
        :title="$t('Reschedule message')"
        :confirm-label="$t('Reschedule')"
        @confirm="onRescheduled"
    />
</template>
