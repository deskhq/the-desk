<script setup lang="ts">
import {
    AlarmClock,
    CornerUpLeft,
    Forward,
    MessageSquareText,
    Pencil,
    Pin,
    SmilePlus,
    Trash2,
} from '@lucide/vue';
import { computed } from 'vue';
import EmojiPickerPopover from '@/components/EmojiPickerPopover.vue';
import MessageReminderPopover from '@/components/MessageReminderPopover.vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCustomEmojis } from '@/composables/useCustomEmojis';
import { useFrequentEmojis } from '@/composables/useFrequentEmojis';
import {
    useMessageActionGuards,
    useMessageActionsContext,
    useMessageSubtree,
} from '@/composables/useMessageActionsContext';
import { useTranslations } from '@/composables/useTranslations';
import type { CustomEmojiEntry } from '@/lib/customEmoji';
import {
    canDeleteMessage,
    canEditMessage,
    canForwardMessage,
    canPinMessage,
    canReactToMessage,
    canRemindAboutMessage,
    canReplyToMessage,
    canStartThreadFromMessage,
    hasAnyMessageAction,
} from '@/lib/messageActions';
import { hasReacted } from '@/lib/reactions';
import type { Message } from '@/types';

const props = defineProps<{
    message: Message;
    /** Whether this row is an optimistic send with no stable server id yet. */
    pending?: boolean;
}>();

const emit = defineEmits<{
    /** Swap this row for its inline editor, which the timeline owns. */
    startEdit: [];
    /** Ask to delete this row, which the timeline confirms first. */
    requestDelete: [];
}>();

const scope = useMessageActionsContext();
const subtree = useMessageSubtree();
const { contextFor } = useMessageActionGuards();

const context = computed(() => contextFor(props.pending ?? false));

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
const showPin = computed(() => canPinMessage(props.message, context.value));
/** The button toggles: a pinned message offers Unpin, an unpinned one offers Pin. */
const isPinned = computed(() => props.message.pin !== null);
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

const { t } = useTranslations();
const { parseToken } = useCustomEmojis();
const { list: frequentEmojis } = useFrequentEmojis();

/**
 * The one-click shortcuts leading the bar. The thread panel is roughly half the
 * channel's width, so it takes the leading three of the same ranked list rather
 * than a list of its own.
 */
const quickEmojis = computed(() =>
    subtree.inThread ? frequentEmojis.value.slice(0, 3) : frequentEmojis.value,
);

/** The emoji the viewer has already reacted with on this message. */
const ownReactions = computed(
    () =>
        new Set(
            props.message.reactions
                .filter((reaction) => hasReacted(reaction, scope.currentUserId))
                .map((reaction) => reaction.emoji),
        ),
);

/**
 * When a shortcut is a resolvable custom-emoji `:name:` token, its image entry;
 * otherwise null (a native glyph, rendered as text).
 */
function quickCustomEmoji(emoji: string): CustomEmojiEntry | null {
    return parseToken(emoji);
}

/** A pressed shortcut retracts on click, so its label flips with the state. */
function quickLabel(emoji: string): string {
    return ownReactions.value.has(emoji)
        ? t('Remove your :emoji', { emoji })
        : t('React with :emoji', { emoji });
}

/** Whether any of the trailing forward…delete cluster renders. */
const showTrailingActions = computed(
    () =>
        showForward.value ||
        showPin.value ||
        showRemind.value ||
        showEdit.value ||
        showDelete.value,
);

/**
 * A hairline divider only earns its place between two rendered clusters. The
 * first sits after the quick-react cluster, the second after the thread/reply
 * pair — each one folds away when either side of it is empty.
 */
const showQuickDivider = computed(
    () =>
        showReact.value &&
        (showStartThread.value || showReply.value || showTrailingActions.value),
);
const showDivider = computed(
    () =>
        (showStartThread.value || showReply.value) && showTrailingActions.value,
);

/**
 * The quick shortcuts share the bar's ghost icon button, adding the pressed
 * (already-reacted) treatment: the brass inset ring + fill the reaction pill
 * uses for the viewer's own reaction, driven by the same `aria-pressed`.
 */
const quickButtonClass =
    'text-muted-foreground aria-pressed:bg-brass-fill aria-pressed:text-brass-fill-foreground aria-pressed:inset-ring-1 aria-pressed:inset-ring-brass-border';

/**
 * The bar's icon buttons ride the `<Button variant="ghost" size="icon-sm">`
 * primitive; these classes only tune what the primitive doesn't own: a muted
 * resting glyph (`ghost` rests transparent), and — for the two popover triggers
 * (react / remind) — an accent-filled "open" state so it reads as active while
 * its menu is attached.
 */
const iconButtonClass = 'text-muted-foreground';
const openStateClass = 'bg-accent text-accent-foreground';

/** Delete recolors to the destructive token on hover instead of neutral. */
const deleteButtonClass =
    'text-muted-foreground hover:bg-destructive/10 hover:text-destructive-text';
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
            <template v-if="showReact">
                <Tooltip v-for="emoji in quickEmojis" :key="emoji">
                    <TooltipTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            type="button"
                            data-test="quick-react"
                            :data-emoji="emoji"
                            :aria-pressed="ownReactions.has(emoji)"
                            :aria-label="quickLabel(emoji)"
                            :class="quickButtonClass"
                            @click="scope.actions.react(props.message, emoji)"
                        >
                            <img
                                v-if="quickCustomEmoji(emoji)"
                                :src="quickCustomEmoji(emoji)!.url"
                                :alt="emoji"
                                class="custom-emoji size-4"
                            />
                            <span
                                v-else
                                class="text-[15px] leading-none"
                                aria-hidden="true"
                                >{{ emoji }}</span
                            >
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent side="top" :side-offset="6">
                        {{ quickLabel(emoji) }}
                    </TooltipContent>
                </Tooltip>

                <EmojiPickerPopover
                    v-slot="{ open }"
                    @select="
                        (emoji) => scope.actions.react(props.message, emoji)
                    "
                >
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        type="button"
                        data-test="message-react"
                        :data-open="open || undefined"
                        :aria-label="$t('Add reaction')"
                        :class="open ? openStateClass : iconButtonClass"
                    >
                        <SmilePlus />
                    </Button>
                </EmojiPickerPopover>
            </template>

            <div
                v-if="showQuickDivider"
                aria-hidden="true"
                class="mx-0.5 h-4 w-px bg-border"
            ></div>

            <Tooltip v-if="showStartThread">
                <TooltipTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        type="button"
                        data-test="message-thread"
                        :aria-label="$t('Reply in thread')"
                        :class="iconButtonClass"
                        @click="scope.actions.openThread(props.message.id)"
                    >
                        <MessageSquareText />
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="top" :side-offset="6">
                    {{ $t('Reply in thread') }}
                </TooltipContent>
            </Tooltip>

            <Tooltip v-if="showReply">
                <TooltipTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        type="button"
                        data-test="message-reply"
                        :aria-label="$t('Reply to message')"
                        :class="iconButtonClass"
                        @click="scope.actions.reply(props.message)"
                    >
                        <CornerUpLeft />
                    </Button>
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
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        type="button"
                        data-test="message-forward"
                        :aria-label="$t('Forward message')"
                        :class="iconButtonClass"
                        @click="scope.actions.forward(props.message)"
                    >
                        <Forward />
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="top" :side-offset="6">
                    {{ $t('Forward message') }}
                </TooltipContent>
            </Tooltip>

            <Tooltip v-if="showPin">
                <TooltipTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        type="button"
                        data-test="message-pin"
                        :aria-label="
                            isPinned
                                ? $t('Unpin from channel')
                                : $t('Pin to channel')
                        "
                        :class="iconButtonClass"
                        @click="
                            isPinned
                                ? scope.actions.unpin(props.message)
                                : scope.actions.pin(props.message)
                        "
                    >
                        <Pin :class="isPinned ? 'fill-brass text-brass' : ''" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="top" :side-offset="6">
                    {{
                        isPinned
                            ? $t('Unpin from channel')
                            : $t('Pin to channel')
                    }}
                </TooltipContent>
            </Tooltip>

            <MessageReminderPopover
                v-if="showRemind"
                v-slot="{ open }"
                :timezone="scope.viewerTimeZone"
                @set="
                    (remindAt) => scope.actions.remind(props.message, remindAt)
                "
                @custom="scope.actions.remindCustom(props.message)"
            >
                <Button
                    variant="ghost"
                    size="icon-sm"
                    type="button"
                    data-test="message-remind"
                    :data-open="open || undefined"
                    :aria-label="$t('Remind me about this')"
                    :class="open ? openStateClass : iconButtonClass"
                >
                    <AlarmClock />
                </Button>
            </MessageReminderPopover>

            <Tooltip v-if="showEdit">
                <TooltipTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        type="button"
                        data-test="message-edit"
                        :aria-label="$t('Edit message')"
                        :class="iconButtonClass"
                        @click="emit('startEdit')"
                    >
                        <Pencil />
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="top" :side-offset="6">
                    {{ $t('Edit message') }}
                </TooltipContent>
            </Tooltip>

            <Tooltip v-if="showDelete">
                <TooltipTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        type="button"
                        data-test="message-delete"
                        :aria-label="$t('Delete message')"
                        :class="deleteButtonClass"
                        @click="emit('requestDelete')"
                    >
                        <Trash2 />
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="top" :side-offset="6">
                    {{ $t('Delete message') }}
                </TooltipContent>
            </Tooltip>
        </div>
    </TooltipProvider>
</template>
