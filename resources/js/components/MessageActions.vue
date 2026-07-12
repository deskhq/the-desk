<script setup lang="ts">
import {
    AlarmClock,
    CornerUpLeft,
    Forward,
    MessageSquareText,
    Pencil,
    SmilePlus,
    Trash2,
} from '@lucide/vue';
import { computed } from 'vue';
import EmojiPickerPopover from '@/components/EmojiPickerPopover.vue';
import MessageReminderPopover from '@/components/MessageReminderPopover.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    canDeleteMessage,
    canEditMessage,
    canForwardMessage,
    canReactToMessage,
    canRemindAboutMessage,
    canReplyToMessage,
    canStartThreadFromMessage,
    hasAnyMessageAction,
} from '@/lib/messageActions';
import type { MessageActionContext } from '@/lib/messageActions';
import type { Message } from '@/types';

const props = defineProps<{
    message: Message;
    currentUserId: string;
    // Whether the viewer may add/remove reactions (member of a live channel).
    canReact?: boolean;
    // Whether the viewer may moderate others' messages (delete them).
    canModerate?: boolean;
    // Rendered inside a thread panel: suppresses the reply/thread affordances.
    inThread?: boolean;
    // Whether this row is an optimistic send with no stable server id yet.
    pending?: boolean;
    // The viewer's stored zone, feeding the reminder popover's wall-clock presets.
    viewerTimezone: string | null;
}>();

const emit = defineEmits<{
    react: [emoji: string];
    reply: [];
    forward: [];
    openThread: [];
    remind: [remindAt: string];
    remindCustom: [];
    edit: [];
    delete: [];
}>();

const context = computed<MessageActionContext>(() => ({
    currentUserId: props.currentUserId,
    canReact: props.canReact ?? false,
    canModerate: props.canModerate ?? false,
    inThread: props.inThread ?? false,
    pending: props.pending ?? false,
}));

const showReact = computed(() =>
    canReactToMessage(props.message, context.value),
);
const showStartThread = computed(() =>
    canStartThreadFromMessage(props.message, context.value),
);
const showReply = computed(() =>
    canReplyToMessage(props.message, context.value),
);
const showForward = computed(() =>
    canForwardMessage(props.message, context.value),
);
const showRemind = computed(() =>
    canRemindAboutMessage(props.message, context.value),
);
const showEdit = computed(() => canEditMessage(props.message, context.value));
const showDelete = computed(() =>
    canDeleteMessage(props.message, context.value),
);
const showBar = computed(() =>
    hasAnyMessageAction(props.message, context.value),
);

// The hairline divider only earns its place when it sits between two clusters:
// the react/reply group and the forward/edit/delete group.
const showDivider = computed(
    () =>
        (showReact.value || showStartThread.value || showReply.value) &&
        (showForward.value ||
            showRemind.value ||
            showEdit.value ||
            showDelete.value),
);

// Shared icon-button treatment: a 30×28 hit area holding a size-3.5 glyph,
// resting muted and lifting to foreground on hover, a legible brass focus ring
// for keyboard users, and a reserved transparent border so the brass open-state
// below never shifts the layout (border-box keeps the footprint fixed).
const iconButtonClass =
    'inline-flex h-7 w-[30px] items-center justify-center rounded-md border border-transparent text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none';

// Delete recolors to the destructive token on hover instead of neutral.
const deleteButtonClass =
    'inline-flex h-7 w-[30px] items-center justify-center rounded-md border border-transparent text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none';

// The persistent "open" state for a button anchoring a popover (react / remind):
// brass carries the meaning that a menu is attached here, per the design's
// deliberate brass-only-when-active call.
const openStateClass =
    'border-brass-border bg-brass-fill text-brass-fill-foreground';
</script>

<template>
    <TooltipProvider
        v-if="showBar"
        :delay-duration="300"
        :skip-delay-duration="150"
    >
        <!--
          The bar rests hidden (opacity-0) but stays focusable, so Tab reveals it
          via group-focus-within just as hover does — the design's "focus-within
          mirrors group-hover". Reveal animates in (fade + zoom, 100ms); hiding is
          instant (the animation classes simply drop) so no ghost bars trail a
          fast scroll. An open emoji/reminder popover pins it via [data-open].
        -->
        <div
            class="pointer-events-none absolute -top-4 right-3 z-10 flex items-center gap-0.5 rounded-lg border border-border bg-background p-0.5 opacity-0 shadow-md group-focus-within/message:pointer-events-auto group-focus-within/message:animate-in group-focus-within/message:opacity-100 group-focus-within/message:duration-100 group-focus-within/message:fade-in-0 group-focus-within/message:zoom-in-95 group-hover/message:pointer-events-auto group-hover/message:animate-in group-hover/message:opacity-100 group-hover/message:duration-100 group-hover/message:fade-in-0 group-hover/message:zoom-in-95 has-[[data-open]]:pointer-events-auto has-[[data-open]]:opacity-100"
        >
            <EmojiPickerPopover
                v-if="showReact"
                v-slot="{ open }"
                :tooltip="$t('Add reaction')"
                @select="(emoji) => emit('react', emoji)"
            >
                <button
                    type="button"
                    data-test="message-react"
                    :data-open="open || undefined"
                    :aria-label="$t('Add reaction')"
                    :class="[iconButtonClass, open ? openStateClass : '']"
                >
                    <SmilePlus class="size-3.5" />
                </button>
            </EmojiPickerPopover>

            <Tooltip v-if="showStartThread">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        data-test="message-thread"
                        :aria-label="$t('Reply in thread')"
                        :class="iconButtonClass"
                        @click="emit('openThread')"
                    >
                        <MessageSquareText class="size-3.5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="top" :side-offset="6">
                    {{ $t('Reply in thread') }}
                </TooltipContent>
            </Tooltip>

            <Tooltip v-if="showReply">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        data-test="message-reply"
                        :aria-label="$t('Reply to message')"
                        :class="iconButtonClass"
                        @click="emit('reply')"
                    >
                        <CornerUpLeft class="size-3.5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="top" :side-offset="6">
                    {{ $t('Reply to message') }}
                </TooltipContent>
            </Tooltip>

            <div
                v-if="showDivider"
                aria-hidden="true"
                class="mx-0.5 h-4 w-px bg-border"
            ></div>

            <Tooltip v-if="showForward">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        data-test="message-forward"
                        :aria-label="$t('Forward message')"
                        :class="iconButtonClass"
                        @click="emit('forward')"
                    >
                        <Forward class="size-3.5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="top" :side-offset="6">
                    {{ $t('Forward message') }}
                </TooltipContent>
            </Tooltip>

            <MessageReminderPopover
                v-if="showRemind"
                v-slot="{ open }"
                :timezone="props.viewerTimezone"
                :tooltip="$t('Remind me about this')"
                @set="(remindAt) => emit('remind', remindAt)"
                @custom="emit('remindCustom')"
            >
                <button
                    type="button"
                    data-test="message-remind"
                    :data-open="open || undefined"
                    :aria-label="$t('Remind me about this')"
                    :class="[iconButtonClass, open ? openStateClass : '']"
                >
                    <AlarmClock class="size-3.5" />
                </button>
            </MessageReminderPopover>

            <Tooltip v-if="showEdit">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        data-test="message-edit"
                        :aria-label="$t('Edit message')"
                        :class="iconButtonClass"
                        @click="emit('edit')"
                    >
                        <Pencil class="size-3.5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="top" :side-offset="6">
                    {{ $t('Edit message') }}
                </TooltipContent>
            </Tooltip>

            <Tooltip v-if="showDelete">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        data-test="message-delete"
                        :aria-label="$t('Delete message')"
                        :class="deleteButtonClass"
                        @click="emit('delete')"
                    >
                        <Trash2 class="size-3.5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="top" :side-offset="6">
                    {{ $t('Delete message') }}
                </TooltipContent>
            </Tooltip>
        </div>
    </TooltipProvider>
</template>
