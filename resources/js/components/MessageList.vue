<script setup lang="ts">
import { Pencil, Trash2 } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useInitials } from '@/composables/useInitials';
import { renderMessageBody } from '@/lib/messageBody';
import type { Message, MessageAuthor } from '@/types';

const props = defineProps<{
    messages: Message[];
    pendingUuids?: string[];
    currentUserId: string;
    canModerate?: boolean;
    onlineIds?: Set<string>;
    highlightMessageId?: string | null;
}>();

const emit = defineEmits<{
    edit: [message: Message, body: string];
    delete: [message: Message];
}>();

const { getInitials } = useInitials();

/**
 * Whether a message author is currently present on the team presence roster.
 */
function isOnline(authorId: string): boolean {
    return props.onlineIds?.has(authorId) ?? false;
}

// Consecutive messages from the same author within this window are grouped
// under a single avatar + header line.
const GROUPING_WINDOW_MS = 5 * 60 * 1000;

type RenderGroup = {
    type: 'group';
    key: string;
    author: MessageAuthor;
    leadCreatedAt: string;
    messages: Message[];
};

type RenderDivider = {
    type: 'divider';
    key: string;
    label: string;
};

type RenderItem = RenderGroup | RenderDivider;

function dayKey(iso: string): string {
    return new Date(iso).toDateString();
}

function dividerLabel(iso: string): string {
    const date = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (dayKey(iso) === today.toDateString()) {
        return 'Today';
    }

    if (dayKey(iso) === yesterday.toDateString()) {
        return 'Yesterday';
    }

    return date.toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year:
            date.getFullYear() === today.getFullYear() ? undefined : 'numeric',
    });
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });
}

const renderItems = computed<RenderItem[]>(() => {
    const items: RenderItem[] = [];
    let currentGroup: RenderGroup | null = null;
    let currentDay: string | null = null;
    let lastCreatedAt: string | null = null;

    for (const message of props.messages) {
        const messageDay = dayKey(message.createdAt);
        const startsNewDay = messageDay !== currentDay;

        if (startsNewDay) {
            items.push({
                type: 'divider',
                key: `divider-${messageDay}`,
                label: dividerLabel(message.createdAt),
            });
            currentDay = messageDay;
        }

        const sameAuthor = currentGroup?.author.id === message.user.id;
        const withinWindow =
            lastCreatedAt !== null &&
            new Date(message.createdAt).getTime() -
                new Date(lastCreatedAt).getTime() <=
                GROUPING_WINDOW_MS;

        if (!currentGroup || startsNewDay || !sameAuthor || !withinWindow) {
            currentGroup = {
                type: 'group',
                key: `group-${message.id}`,
                author: message.user,
                leadCreatedAt: message.createdAt,
                messages: [message],
            };
            items.push(currentGroup);
        } else {
            currentGroup.messages.push(message);
        }

        lastCreatedAt = message.createdAt;
    }

    return items;
});

const pending = computed(() => new Set(props.pendingUuids ?? []));

function isPending(message: Message): boolean {
    return pending.value.has(message.clientUuid);
}

function isOwn(message: Message): boolean {
    return message.user.id === props.currentUserId;
}

function canEdit(message: Message): boolean {
    return !message.isDeleted && !isPending(message) && isOwn(message);
}

function canDelete(message: Message): boolean {
    return (
        !message.isDeleted &&
        !isPending(message) &&
        (isOwn(message) || Boolean(props.canModerate))
    );
}

// The message currently being edited inline, and its working draft.
const editingId = ref<string | null>(null);
const editDraft = ref('');
const editField = ref<HTMLTextAreaElement | null>(null);

// A plain template ref inside v-for collects into an array; a function ref keeps
// the single visible editor element (only one row edits at a time).
function setEditField(el: unknown): void {
    editField.value = (el as HTMLTextAreaElement | null) ?? null;
}

function startEdit(message: Message): void {
    editingId.value = message.id;
    editDraft.value = message.body;
    nextTick(() => editField.value?.focus());
}

function cancelEdit(): void {
    editingId.value = null;
    editDraft.value = '';
}

function saveEdit(message: Message): void {
    const body = editDraft.value.trim();

    // An empty or unchanged draft is a no-op; the server would reject the former.
    if (body !== '' && body !== message.body) {
        emit('edit', message, body);
    }

    cancelEdit();
}

// The message queued for deletion; a non-null value drives the confirm dialog.
const pendingDelete = ref<Message | null>(null);

function requestDelete(message: Message): void {
    pendingDelete.value = message;
}

function setDeleteOpen(open: boolean): void {
    if (!open) {
        pendingDelete.value = null;
    }
}

function confirmDelete(): void {
    if (pendingDelete.value) {
        emit('delete', pendingDelete.value);
    }

    pendingDelete.value = null;
}
</script>

<template>
    <div class="px-5 pt-4 pb-2">
        <template v-for="item in renderItems" :key="item.key">
            <div
                v-if="item.type === 'divider'"
                class="relative my-4 flex items-center justify-center"
            >
                <span
                    aria-hidden="true"
                    class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-border"
                />
                <span
                    class="relative rounded-full border border-border bg-background px-3 py-0.5 text-[11.5px] font-medium text-muted-foreground"
                >
                    {{ item.label }}
                </span>
            </div>

            <div v-else class="mt-[18px] flex gap-3 first:mt-1">
                <div class="relative size-9 shrink-0">
                    <div
                        class="flex size-9 items-center justify-center rounded-[10px] bg-primary/10 text-[12px] font-semibold text-primary select-none"
                        aria-hidden="true"
                    >
                        {{ getInitials(item.author.name) }}
                    </div>
                    <span
                        data-test="presence-dot"
                        :data-online="isOnline(item.author.id)"
                        :aria-label="
                            isOnline(item.author.id) ? 'Online' : 'Offline'
                        "
                        class="absolute -right-0.5 -bottom-0.5 size-2.5 rounded-full ring-2 ring-background"
                        :class="
                            isOnline(item.author.id)
                                ? 'bg-emerald-500'
                                : 'bg-transparent ring-muted-foreground/40 ring-inset'
                        "
                    />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline gap-2">
                        <span
                            class="text-[14.5px] font-semibold text-foreground"
                            >{{ item.author.name }}</span
                        >
                        <span class="text-[11px] text-muted-foreground/80">{{
                            formatTime(item.leadCreatedAt)
                        }}</span>
                    </div>
                    <div
                        v-for="(message, index) in item.messages"
                        :id="`message-${message.id}`"
                        :key="message.id"
                        class="group/message relative -mx-2 rounded-md px-2 transition-colors duration-1000 hover:bg-muted/40"
                        :class="[
                            index === 0 ? 'mt-0.5' : 'mt-1.5',
                            message.id === props.highlightMessageId
                                ? 'bg-primary/10'
                                : '',
                        ]"
                    >
                        <p
                            v-if="message.isDeleted"
                            :data-test="'message-tombstone'"
                            class="py-0.5 text-[13.5px] text-muted-foreground/70 italic"
                        >
                            This message was deleted
                        </p>

                        <div
                            v-else-if="editingId === message.id"
                            class="py-0.5"
                        >
                            <textarea
                                :ref="setEditField"
                                v-model="editDraft"
                                rows="1"
                                class="w-full resize-none rounded-md border border-input bg-background px-2.5 py-1.5 text-[14.5px] leading-[1.55] text-foreground outline-none focus:border-ring focus:ring-1 focus:ring-ring"
                                @keydown.enter.exact.prevent="saveEdit(message)"
                                @keydown.esc.prevent="cancelEdit"
                            ></textarea>
                            <div
                                class="mt-1 flex items-center gap-2 text-[11.5px] text-muted-foreground"
                            >
                                <button
                                    type="button"
                                    class="rounded bg-primary px-2 py-1 font-medium text-primary-foreground hover:bg-primary/90"
                                    @click="saveEdit(message)"
                                >
                                    Save
                                </button>
                                <button
                                    type="button"
                                    class="rounded border border-border px-2 py-1 font-medium text-foreground hover:bg-muted"
                                    @click="cancelEdit"
                                >
                                    Cancel
                                </button>
                                <span>Enter to save · Esc to cancel</span>
                            </div>
                        </div>

                        <p
                            v-else
                            :data-test="'message-body'"
                            class="py-0.5 text-[14.5px] leading-[1.55] break-words whitespace-pre-wrap text-foreground/90"
                            :class="isPending(message) ? 'opacity-60' : ''"
                        >
                            <span
                                v-html="
                                    renderMessageBody(
                                        message.body,
                                        message.mentions,
                                    )
                                "
                            ></span>
                            <span
                                v-if="message.editedAt"
                                :data-test="'message-edited'"
                                class="ml-1 align-baseline text-[11px] text-muted-foreground/70 select-none"
                                >(edited)</span
                            >
                        </p>

                        <div
                            v-if="
                                editingId !== message.id &&
                                (canEdit(message) || canDelete(message))
                            "
                            class="absolute -top-3 right-2 hidden items-center gap-0.5 rounded-md border border-border bg-background p-0.5 shadow-sm group-hover/message:flex"
                        >
                            <button
                                v-if="canEdit(message)"
                                type="button"
                                :data-test="'message-edit'"
                                aria-label="Edit message"
                                class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                @click="startEdit(message)"
                            >
                                <Pencil class="size-3.5" />
                            </button>
                            <button
                                v-if="canDelete(message)"
                                type="button"
                                :data-test="'message-delete'"
                                aria-label="Delete message"
                                class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive"
                                @click="requestDelete(message)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <Dialog :open="pendingDelete !== null" @update:open="setDeleteOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete message</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to delete this message? This can't
                        be undone.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary"> Cancel </Button>
                    </DialogClose>

                    <Button
                        data-test="delete-message-confirm"
                        variant="destructive"
                        @click="confirmDelete"
                    >
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
