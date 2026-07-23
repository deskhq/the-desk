<script setup lang="ts">
import { Calendar, ChevronDown, Clock, Pencil, Trash2 } from '@lucide/vue';
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
    channelName: string;
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
        <DialogContent mobile="detail" class="gap-0 p-0 sm:max-w-lg">
            <DialogHeader class="gap-1 px-6 pt-6 pb-4">
                <div class="flex items-center gap-2 pr-7">
                    <DialogTitle class="text-[20px]">{{
                        $t('Scheduled messages')
                    }}</DialogTitle>
                    <span
                        v-if="props.scheduledMessages.length > 0"
                        data-test="scheduled-count"
                        class="inline-flex h-5 items-center rounded-full border border-brass-border/30 bg-brass-fill px-2 font-sans text-[11px] font-semibold text-brass-fill-foreground"
                    >
                        {{ props.scheduledMessages.length }}
                    </span>
                </div>
                <DialogDescription class="text-[12.5px]">
                    {{
                        $t(
                            "Waiting to be sent to #:channel. Edit or cancel any that haven't gone out yet.",
                            { channel: props.channelName },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <ul
                v-if="props.scheduledMessages.length > 0"
                class="max-h-[28rem] space-y-2 overflow-y-auto px-4 pb-4"
                data-test="scheduled-list"
            >
                <li
                    v-for="scheduled in props.scheduledMessages"
                    :key="scheduled.id"
                    data-test="scheduled-item"
                >
                    <!-- Edit mode: an elevated card with the body textarea, a
                         brass chip that reopens the schedule dialog, and
                         Discard / Save. -->
                    <div
                        v-if="editingId === scheduled.id"
                        class="flex flex-col gap-2.5 rounded-xl border border-input bg-card p-3.5 shadow-[0_4px_14px_rgba(29,26,21,0.08)]"
                    >
                        <textarea
                            v-model="editBody"
                            rows="2"
                            :aria-label="$t('Scheduled message body')"
                            data-test="scheduled-edit-body"
                            class="w-full resize-none rounded-lg border border-input bg-popover px-3 py-2.5 text-[13.5px] leading-[1.5] text-foreground outline-none focus:border-ring focus:ring-1 focus:ring-ring"
                        ></textarea>
                        <div class="flex items-center gap-2">
                            <Button
                                variant="unstyled"
                                size="none"
                                type="button"
                                data-test="scheduled-reschedule"
                                class="inline-flex items-center gap-1.5 rounded-full border border-dashed border-brass-border bg-brass-fill px-3 py-1.5 text-[12px] font-semibold text-brass-fill-foreground hover:bg-brass-fill/70"
                                @click="rescheduling = true"
                            >
                                <Calendar class="size-3 text-brass" />
                                {{ whenLabel(editSendAt) }}
                                <ChevronDown class="size-3 text-brass" />
                            </Button>
                            <div class="ml-auto flex items-center gap-2">
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    class="h-7.5 rounded-full"
                                    @click="cancelEdit"
                                >
                                    {{ $t('Discard') }}
                                </Button>
                                <Button
                                    size="sm"
                                    class="h-7.5 rounded-full"
                                    data-test="scheduled-save"
                                    :disabled="!canSave"
                                    @click="saveEdit"
                                >
                                    {{ $t('Save') }}
                                </Button>
                            </div>
                        </div>
                    </div>

                    <!-- View mode: a warm card showing the body, an optional
                         reply-quote, the brass "sends at" chip, and the
                         edit / cancel actions. -->
                    <div
                        v-else
                        class="group flex flex-col gap-2 rounded-xl border border-border bg-card p-3.5"
                    >
                        <MessageQuote
                            v-if="scheduled.replyTo"
                            :author-name="scheduled.replyTo.authorName"
                            :body="scheduled.replyTo.body"
                            :is-deleted="scheduled.replyTo.isDeleted"
                            class="rounded-r-lg bg-brass-fill py-1.5 pr-3"
                        />
                        <p class="line-clamp-3 text-[13.5px] text-foreground">
                            {{ bodyPreview(scheduled.body) }}
                        </p>
                        <div class="flex items-center gap-2">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full border border-brass-border/30 bg-brass-fill px-2.5 py-1 text-[11.5px] font-semibold text-brass-fill-foreground"
                                data-test="scheduled-when"
                            >
                                <Clock class="size-3 text-brass" />
                                {{ whenLabel(scheduled.sendAt) }}
                            </span>
                            <div class="ml-auto flex items-center gap-0.5">
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    type="button"
                                    :aria-label="$t('Edit scheduled message')"
                                    data-test="scheduled-edit"
                                    class="size-7 rounded-full text-muted-foreground"
                                    @click="startEdit(scheduled)"
                                >
                                    <Pencil class="size-3.5" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    type="button"
                                    :aria-label="$t('Cancel scheduled message')"
                                    data-test="scheduled-cancel"
                                    class="size-7 rounded-full text-muted-foreground hover:bg-destructive/10 hover:text-destructive-text"
                                    @click="cancelSend(scheduled)"
                                >
                                    <Trash2 class="size-3.5" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </li>
            </ul>

            <p
                v-else
                data-test="scheduled-empty"
                class="px-6 py-8 text-center text-[13px] text-muted-foreground"
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
