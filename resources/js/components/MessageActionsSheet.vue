<script setup lang="ts">
import {
    AlarmClock,
    Copy,
    CornerUpLeft,
    Forward,
    MessageSquareText,
    Pencil,
    Pin,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, watch } from 'vue';
import EmojiPickerPopover from '@/components/EmojiPickerPopover.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useCustomEmojis } from '@/composables/useCustomEmojis';
import { useFrequentEmojis } from '@/composables/useFrequentEmojis';
import { useInitials } from '@/composables/useInitials';
import {
    useMessageActionGuards,
    useMessageActionsContext,
} from '@/composables/useMessageActionsContext';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import { formatTimeOfDay } from '@/lib/datetime';
import {
    canCopyMessage,
    canDeleteMessage,
    canEditMessage,
    canForwardMessage,
    canPinMessage,
    canReactToMessage,
    canRemindAboutMessage,
    canReplyToMessage,
    canStartThreadFromMessage,
} from '@/lib/messageActions';
import { messageBodyCopyText, messageBodyPreview } from '@/lib/messageBody';
import { hasReacted } from '@/lib/reactions';
import type { Message } from '@/types';

const props = defineProps<{
    /** Whether the sheet is presented. */
    open: boolean;
    /** The long-pressed message, kept through the close animation. */
    message: Message | null;
    /** Whether the pressed row is an optimistic send with no stable server id yet. */
    pending?: boolean;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    /** Swap the pressed row for its inline editor, which the timeline owns. */
    startEdit: [];
    /** Ask to delete the pressed row, which the timeline confirms first. */
    requestDelete: [];
}>();

const { t } = useTranslations();
const toast = useToast();
const { getInitials } = useInitials();
const { parseToken } = useCustomEmojis();
const { list: frequentEmojis } = useFrequentEmojis();

const scope = useMessageActionsContext();
const { contextFor } = useMessageActionGuards();

const context = computed(() => contextFor(props.pending ?? false));

/**
 * The sheet mirrors the hover toolbar action for action, so both surfaces read
 * visibility from the same shared guards and can never drift apart.
 */
const showReact = computed(
    () =>
        props.message !== null &&
        canReactToMessage(props.message, context.value),
);
const showThread = computed(
    () =>
        props.message !== null &&
        canStartThreadFromMessage(props.message, context.value),
);
const showReply = computed(
    () =>
        props.message !== null &&
        canReplyToMessage(props.message, context.value),
);
const showForward = computed(
    () =>
        props.message !== null &&
        canForwardMessage(props.message, context.value),
);
/**
 * The text the copy row puts on the clipboard: the body as typed, with mention
 * tokens resolved. An empty value (attachment-only or poll message) hides the
 * row — there is nothing to copy.
 */
const copyText = computed(() =>
    props.message === null ? '' : messageBodyCopyText(props.message.body),
);
const showCopy = computed(
    () =>
        props.message !== null &&
        canCopyMessage(props.message, context.value) &&
        copyText.value.trim() !== '',
);
const showPin = computed(
    () => props.message !== null && canPinMessage(props.message, context.value),
);
const isPinned = computed(() => props.message?.pin != null);
const showRemind = computed(
    () =>
        props.message !== null &&
        canRemindAboutMessage(props.message, context.value),
);
const showEdit = computed(
    () =>
        props.message !== null && canEditMessage(props.message, context.value),
);
const showDelete = computed(
    () =>
        props.message !== null &&
        canDeleteMessage(props.message, context.value),
);

/** The leading five one-tap shortcuts, evenly spaced across the strip. */
const quickEmojis = computed(() => frequentEmojis.value.slice(0, 5));

/** The emoji the viewer has already reacted with on this message. */
const ownReactions = computed(
    () =>
        new Set(
            (props.message?.reactions ?? [])
                .filter((entry) => hasReacted(entry, scope.currentUserId))
                .map((entry) => entry.emoji),
        ),
);

/** A pressed shortcut retracts on tap, so its label flips with the state. */
function quickLabel(emoji: string): string {
    return ownReactions.value.has(emoji)
        ? t('Remove your :emoji', { emoji })
        : t('React with :emoji', { emoji });
}

/** The lifted card's plain-text snippet, clamped by the template. */
const bodyPreview = computed(() =>
    props.message === null || props.message.isDeleted
        ? ''
        : messageBodyPreview(props.message.body),
);

const timestamp = computed(() =>
    props.message === null
        ? ''
        : formatTimeOfDay(
              props.message.createdAt,
              scope.viewerTimeZone ?? undefined,
          ),
);

function close(): void {
    emit('update:open', false);
}

// WebKit starts its selection gesture in parallel with the long-press timer, so
// a selection can land just before the sheet does; left alive, its handles sit
// on top of the scrim (#800). Immediate, so a sheet mounted open clears it too.
watch(
    () => props.open,
    (open) => {
        if (open) {
            window.getSelection()?.removeAllRanges();
        }
    },
    { immediate: true },
);

/**
 * Every action leaves the sheet behind: run it against the pressed message,
 * then dismiss. Each row is guarded by a rule that already requires a message,
 * so the null branch is only reachable if a row outlives its own guard.
 */
function act(run: (message: Message) => void): void {
    if (props.message !== null) {
        run(props.message);
    }

    close();
}

/**
 * The mobile stand-in for selecting the text: the rows below `md` suppress
 * native selection so the long-press can win (#800), and this row is the
 * deliberate copy path that replaces it.
 */
async function copyMessageText(): Promise<void> {
    try {
        // Also guards the API being absent altogether (a plain-http install).
        await navigator.clipboard.writeText(copyText.value);
    } catch {
        toast.error(t('The message text could not be copied'));
    } finally {
        close();
    }
}

/** The shared look of one 46px action row (design m4). */
const rowClass =
    'flex h-11.5 w-full items-center gap-3 rounded-[11px] px-3.5 text-left text-[14.5px] font-medium text-foreground transition-colors hover:bg-muted/50 active:bg-muted';
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            data-test="message-actions-sheet"
            :show-close-button="false"
            class="gap-0 overflow-visible px-2.5 select-none [-webkit-touch-callout:none]"
        >
            <DialogTitle class="sr-only">{{
                $t('Message actions')
            }}</DialogTitle>
            <DialogDescription class="sr-only">
                {{ $t('Choose an action for this message.') }}
            </DialogDescription>

            <!-- The pressed message, lifted onto the scrim above the sheet so it
                 stays visible while choosing an action (design m4). Purely a
                 visual echo of the row: the sheet's title already names the
                 surface, so the card is decorative to a screen reader. On a
                 short (landscape) viewport the sheet's 85dvh cap leaves no
                 scrim for the card to sit on, so it folds away rather than
                 poking off the top of the screen. -->
            <div
                v-if="message"
                aria-hidden="true"
                data-test="lifted-message"
                class="pointer-events-none absolute inset-x-1.5 bottom-full mb-3 rounded-[14px] border border-border bg-card p-3 shadow-[0_12px_32px_rgba(29,26,21,0.3)] [@media(max-height:500px)]:hidden"
            >
                <div class="flex gap-2.5">
                    <Avatar class="size-7.5 shrink-0 text-[10px]">
                        <AvatarImage
                            v-if="message.user.avatar"
                            :src="message.user.avatar"
                            :alt="message.user.name"
                        />
                        <AvatarFallback
                            class="bg-primary/10 font-semibold text-primary"
                        >
                            {{ getInitials(message.user.name) }}
                        </AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <div class="text-[13px] font-semibold text-foreground">
                            {{ message.user.name }}
                            <span
                                class="ml-1 font-mono text-[10px] font-normal text-muted-foreground"
                                >{{ timestamp }}</span
                            >
                        </div>
                        <p
                            class="mt-px line-clamp-4 text-[13.5px] leading-normal text-foreground/85"
                        >
                            {{ bodyPreview }}
                        </p>
                    </div>
                </div>
            </div>

            <div
                v-if="showReact"
                class="flex items-center justify-between border-b border-border px-3.5 pt-0.5 pb-3"
            >
                <Button
                    v-for="emoji in quickEmojis"
                    :key="emoji"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="sheet-quick-react"
                    :data-emoji="emoji"
                    :aria-pressed="ownReactions.has(emoji)"
                    :aria-label="quickLabel(emoji)"
                    class="flex size-11 items-center justify-center rounded-full bg-muted text-[19px] leading-none transition-colors hover:bg-accent aria-pressed:bg-brass-fill aria-pressed:text-brass-fill-foreground aria-pressed:inset-ring-1 aria-pressed:inset-ring-brass-border"
                    @click="act((target) => scope.actions.react(target, emoji))"
                >
                    <img
                        v-if="parseToken(emoji)"
                        :src="parseToken(emoji)!.url"
                        :alt="emoji"
                        class="custom-emoji size-5"
                    />
                    <span v-else aria-hidden="true">{{ emoji }}</span>
                </Button>

                <EmojiPickerPopover
                    @select="
                        (emoji) =>
                            act((target) => scope.actions.react(target, emoji))
                    "
                >
                    <Button
                        variant="unstyled"
                        size="none"
                        type="button"
                        data-test="sheet-react"
                        :aria-label="$t('Add reaction')"
                        class="flex size-11 items-center justify-center rounded-full border border-dashed border-border text-muted-foreground transition-colors hover:bg-accent"
                    >
                        <Plus class="size-4" />
                    </Button>
                </EmojiPickerPopover>
            </div>

            <nav
                class="flex flex-col pt-1.5"
                :aria-label="$t('Message actions')"
            >
                <Button
                    v-if="showThread"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="sheet-thread"
                    :class="rowClass"
                    @click="
                        act((target) => scope.actions.openThread(target.id))
                    "
                >
                    <MessageSquareText class="size-4 text-muted-foreground" />
                    {{ $t('Reply in thread') }}
                </Button>
                <Button
                    v-if="showReply"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="sheet-reply"
                    :class="rowClass"
                    @click="act(scope.actions.reply)"
                >
                    <CornerUpLeft class="size-4 text-muted-foreground" />
                    {{ $t('Reply to message') }}
                </Button>
                <Button
                    v-if="showForward"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="sheet-forward"
                    :class="rowClass"
                    @click="act(scope.actions.forward)"
                >
                    <Forward class="size-4 text-muted-foreground" />
                    {{ $t('Forward') }}
                </Button>
                <Button
                    v-if="showCopy"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="sheet-copy"
                    :class="rowClass"
                    @click="copyMessageText"
                >
                    <Copy class="size-4 text-muted-foreground" />
                    {{ $t('Copy text') }}
                </Button>
                <Button
                    v-if="showPin"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="sheet-pin"
                    :class="rowClass"
                    @click="
                        act(isPinned ? scope.actions.unpin : scope.actions.pin)
                    "
                >
                    <Pin
                        class="size-4"
                        :class="
                            isPinned
                                ? 'fill-brass text-brass'
                                : 'text-muted-foreground'
                        "
                    />
                    {{
                        isPinned
                            ? $t('Unpin from channel')
                            : $t('Pin to channel')
                    }}
                </Button>
                <Button
                    v-if="showRemind"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="sheet-remind"
                    :class="rowClass"
                    @click="act(scope.actions.remindCustom)"
                >
                    <AlarmClock class="size-4 text-muted-foreground" />
                    {{ $t('Remind me…') }}
                </Button>
                <Button
                    v-if="showEdit"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="sheet-edit"
                    :class="rowClass"
                    @click="act(() => emit('startEdit'))"
                >
                    <Pencil class="size-4 text-muted-foreground" />
                    {{ $t('Edit message') }}
                </Button>
                <Button
                    v-if="showDelete"
                    variant="unstyled"
                    size="none"
                    type="button"
                    data-test="sheet-delete"
                    class="flex h-11.5 w-full items-center gap-3 rounded-[11px] px-3.5 text-left text-[14.5px] font-medium text-destructive-text transition-colors hover:bg-destructive/10 active:bg-destructive/10"
                    @click="act(() => emit('requestDelete'))"
                >
                    <Trash2 class="size-4" />
                    {{ $t('Delete message') }}
                </Button>
            </nav>
        </DialogContent>
    </Dialog>
</template>
