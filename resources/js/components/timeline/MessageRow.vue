<script setup lang="ts">
import { Clock, Pin } from '@lucide/vue';
import { computed } from 'vue';
import MessageActions from '@/components/MessageActions.vue';
import MessageAttachments from '@/components/MessageAttachments.vue';
import MessageForward from '@/components/MessageForward.vue';
import MessagePoll from '@/components/MessagePoll.vue';
import MessageReactions from '@/components/MessageReactions.vue';
import MessageBodyText from '@/components/timeline/MessageBodyText.vue';
import MessageEditForm from '@/components/timeline/MessageEditForm.vue';
import MessageReplyQuote from '@/components/timeline/MessageReplyQuote.vue';
import ThreadSummary from '@/components/timeline/ThreadSummary.vue';
import type { UseLongPress } from '@/composables/useLongPress';
import {
    useMessageActionGuards,
    useMessageActionsContext,
    useMessageSubtree,
} from '@/composables/useMessageActionsContext';
import { formatTimeOfDay } from '@/lib/datetime';
import { canReactToMessage, showsThreadSummary } from '@/lib/messageActions';
import { messageAccessibleName } from '@/lib/timeline';
import type { Message } from '@/types';

/**
 * Below `md`, a rich card (poll, attachments, reply quote) breaks out of the
 * message row so it runs the full width of the timeline instead of the
 * 36px-indented text column, trading its rounded box for hairline top and
 * bottom rules (design "Mobile Message List", screen `1c`).
 *
 * The offsets are this row's own geometry — 20px of timeline padding plus the
 * 36px avatar gutter on the left, 20px of padding on the right — so they live
 * beside the row that defines them rather than inside each card.
 */
const CARD_BLEED =
    'max-md:-ml-14 max-md:-mr-5 max-md:rounded-none max-md:border-x-0';

const props = defineProps<{
    message: Message;
    /** The group author's name, naming the row for assistive technology. */
    authorName: string;
    teamSlug: string;
    /** Whether this row leads its author group, which sets its top margin. */
    isLead: boolean;
    /** Whether the send is still in flight to the server. */
    pending: boolean;
    /** Whether the send is held in the offline outbox. */
    queued: boolean;
    /** Whether the row is under a live long press, showing its hold cue. */
    held: boolean;
    /** Whether this row is the one swapped into its inline editor. */
    editing: boolean;
    /** Whether this row is the current jump target. */
    highlighted: boolean;
    /** Whether this row is the root of the thread open beside the timeline. */
    activeThreadRoot: boolean;
    /** Whether the composer holds this row's text for an edit. */
    composerEditing: boolean;
    /** The gesture opening the touch actions sheet, owned by the timeline. */
    longPress: UseLongPress<Message>;
}>();

const emit = defineEmits<{
    /** Open this row's inline editor. */
    startEdit: [];
    /** Commit the inline editor's draft. */
    saveEdit: [body: string];
    /** Leave the inline editor without committing. */
    cancelEdit: [];
    /** Ask to delete this row, which the timeline confirms first. */
    requestDelete: [];
}>();

const scope = useMessageActionsContext();
const subtree = useMessageSubtree();
const { contextFor } = useMessageActionGuards();

/**
 * The viewer's zone as `Intl` takes it: the context stores "none" as null, and
 * an absent zone is what renders a timestamp in the browser's own.
 */
const timeZone = computed(() => scope.viewerTimeZone ?? undefined);

function formatTime(iso: string): string {
    return formatTimeOfDay(iso, timeZone.value);
}

/**
 * The viewer context each per-message guard resolves against. The hover-action
 * bar (extracted to MessageActions) owns the toolbar guards; the two rules below
 * stay here because they drive non-toolbar UI — the reaction pills and the
 * thread summary — and share the same context.
 */
const actionContext = computed(() => contextFor(props.pending));

/**
 * The viewer may react to any live, confirmed message when they're a member of
 * the (non-archived) channel; drives whether the reaction pills accept toggles.
 */
const canReactTo = computed(() =>
    canReactToMessage(props.message, actionContext.value),
);

/**
 * The "N replies" affordance shows on any root that has replies, even a deleted
 * one — the thread outlives its root as a tombstone.
 */
const showThreadSummary = computed(() =>
    showsThreadSummary(props.message, actionContext.value),
);
</script>

<template>
    <!-- eslint-disable-next-line vuejs-accessibility/no-static-element-interactions -- a touch-only long-press gesture; every action it opens stays reachable through the toolbar's focusable buttons -->
    <div
        :id="`message-${message.id}`"
        role="listitem"
        :aria-label="
            messageAccessibleName(authorName, message.createdAt, timeZone)
        "
        class="group/message relative -mx-2 rounded-md px-2 transition-colors duration-1000 hover:bg-muted/40 max-md:select-none max-md:[-webkit-touch-callout:none]"
        :class="[
            isLead ? 'mt-0.5' : 'mt-1.5 max-md:mt-0.5',
            highlighted ? 'bg-primary/10' : '',
            activeThreadRoot ? 'bg-primary/5' : '',
            composerEditing ? 'bg-brass-fill' : '',
            held
                ? 'scale-[0.98] opacity-80 transition-[transform,opacity] delay-100 duration-200'
                : '',
        ]"
        @pointerdown="longPress.start($event, message)"
        @pointermove="longPress.move($event)"
        @pointerup="longPress.end()"
        @pointerleave="longPress.end()"
        @pointercancel="longPress.cancel()"
        @contextmenu="longPress.onContextMenu($event)"
    >
        <!-- Per-message timestamp: revealed in the avatar gutter on hover, and
             hidden from AT since the row's accessible name already carries the
             time. The `<time datetime>` keeps the row machine-readable
             regardless of the hover styling.
             Only non-lead rows reveal it on hover — the lead row already shows
             the group time above, so revealing this here would stack a duplicate
             on top of it. The lead's <time> stays present but never fades in, so
             its machine-readable value is unchanged.
             Touch has no hover to reveal it with, so below `md` it drops out
             entirely rather than compete for a 36px gutter. -->
        <time
            :datetime="message.createdAt"
            data-test="message-hover-time"
            aria-hidden="true"
            class="pointer-events-none absolute top-1.5 -left-10.75 -translate-x-1/2 font-mono text-[9.5px] text-muted-foreground opacity-0 transition-opacity max-md:hidden"
            :class="isLead ? '' : 'group-hover/message:opacity-100'"
            >{{ formatTime(message.createdAt) }}</time
        >

        <!-- Pinned indicator: renders whenever the message is pinned, in the
             same slot as the "edited" treatment above the body. Brass marks
             pinned-ness; the content column is already indented, so it aligns to
             the text column. -->
        <div
            v-if="message.pin"
            data-test="message-pinned-indicator"
            class="flex items-center gap-1.5 pt-0.5 text-[11px] text-muted-foreground"
        >
            <Pin class="size-3 fill-brass text-brass" />
            <span>{{
                $t('Pinned by :name', { name: message.pin.pinnedBy.name })
            }}</span>
        </div>

        <MessageReplyQuote
            v-if="message.replyTo && !message.isDeleted && !editing"
            :reply-to="message.replyTo"
            :bleed-class="CARD_BLEED"
            @jump="(id) => scope.actions.jump(id)"
        />

        <p
            v-if="message.isDeleted"
            :data-test="'message-tombstone'"
            class="py-0.5 font-serif text-[13.5px] text-muted-foreground italic"
        >
            {{ $t('This message was deleted') }}
        </p>

        <MessageEditForm
            v-else-if="editing"
            :body="message.body"
            @save="(body) => emit('saveEdit', body)"
            @cancel="emit('cancelEdit')"
        />

        <MessageBodyText
            v-else-if="message.body !== ''"
            :message="message"
            :team-slug="teamSlug"
            :pending="pending"
            @mention="(member) => subtree.mention(member)"
        />

        <MessageAttachments
            v-if="
                message.attachments.length > 0 && !message.isDeleted && !editing
            "
            :class="CARD_BLEED"
            :attachments="message.attachments"
            :author-name="message.user.name"
            :created-at="message.createdAt"
            :viewer-time-zone="timeZone"
        />

        <MessagePoll
            v-if="message.poll && !message.isDeleted && !editing"
            :class="CARD_BLEED"
            :poll="message.poll"
            :current-user-id="scope.currentUserId"
            :can-vote="canReactTo"
            :can-manage="
                message.user.id === scope.currentUserId || scope.canModerate
            "
            @vote="(optionId) => scope.actions.vote(message, optionId)"
            @close="scope.actions.closePoll(message)"
        />

        <span
            v-if="queued"
            data-test="message-queued"
            class="mt-0.5 inline-flex items-center gap-1 text-[11px] font-semibold text-muted-foreground select-none"
        >
            <Clock class="size-3" />
            {{ $t('Queued — will send on reconnect') }}
        </span>

        <MessageForward
            v-if="message.forwardedFrom && !message.isDeleted && !editing"
            data-test="forwarded-message"
            :author-name="message.forwardedFrom.authorName"
            :author-is-bot="message.forwardedFrom.authorIsBot"
            :author-override="message.forwardedFrom.authorOverride"
            :channel-name="message.forwardedFrom.channelName"
            :body="message.forwardedFrom.body"
            :is-deleted="message.forwardedFrom.isDeleted"
            :mentions="message.forwardedFrom.mentions"
        />

        <MessageReactions
            v-if="!message.isDeleted && !editing"
            :reactions="message.reactions"
            :current-user-id="scope.currentUserId"
            :can-react="canReactTo"
            @toggle="(emoji) => scope.actions.react(message, emoji)"
        />

        <ThreadSummary
            v-if="showThreadSummary"
            :message="message"
            :last-reply-time="
                message.threadLastReplyAt
                    ? formatTime(message.threadLastReplyAt)
                    : ''
            "
            @open-thread="(id) => scope.actions.openThread(id)"
        />

        <MessageActions
            v-if="!editing"
            :message="message"
            :pending="pending"
            @start-edit="emit('startEdit')"
            @request-delete="emit('requestDelete')"
        />
    </div>
</template>
