<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import OfflineQueueBanner from '@/components/channel/OfflineQueueBanner.vue';
import MessageComposer from '@/components/MessageComposer.vue';
import ScheduledCountChip from '@/components/ScheduledCountChip.vue';
import TypingIndicator from '@/components/TypingIndicator.vue';
import type {
    CommandCallbacks,
    SendCallbacks,
} from '@/composables/useMessageActions';
import type {
    Channel,
    Mention,
    Message,
    PersonRef,
    RosterMember,
    ScheduledMessage,
} from '@/types';

/**
 * The bottom of the conversation: the offline queue banner, the typing line, the
 * scheduled-message chip and the composer itself. The page keeps ownership of
 * what the composer sends; this owns only the furniture stacked above it.
 */
const props = defineProps<{
    team: { slug: string };
    channel: Channel;
    /** The members the composer offers for `@`, the current user and bots aside. */
    members: RosterMember[];
    /** Whether the channel has a bot member, noted once in the mention menu. */
    hasBots: boolean;
    /** A DM addresses the conversation by name rather than by "#channel". */
    placeholder?: string;
    replyTarget: Message | null;
    /** The live-merged timeline, so ↑ can edit the viewer's last message. */
    messages: Message[];
    currentUserId: string;
    pendingUuids: string[];
    timezone: string | null;
    typingNames: string[];
    scheduledMessages: ScheduledMessage[];
    /** How many sends are held in the offline queue, and whether it is showing. */
    queuedCount: number;
    isOffline: boolean;
}>();

const emit = defineEmits<{
    /**
     * The composer's `sendToChannel` flag is dropped on the way out: it belongs
     * to the thread composer's "also send to channel" box, which a channel
     * composer never shows.
     */
    send: [
        body: string,
        mentions: Mention[],
        attachmentIds: string[],
        callbacks: SendCallbacks,
    ];
    command: [body: string, callbacks: CommandCallbacks];
    schedule: [body: string, mentions: Mention[], sendAt: string];
    typing: [];
    cancelReply: [];
    draftChange: [body: string];
    edit: [message: Message, body: string];
    editingChange: [messageId: string | null];
    /** The viewer threw the offline queue away. */
    discardQueue: [];
    /** The chip was used: open the scheduled-messages dialog. */
    viewScheduled: [];
}>();

const page = usePage();

const composer = ref<InstanceType<typeof MessageComposer> | null>(null);

/**
 * The composer's own element, which the page publishes as the bottom-right
 * rail's inset so toasts sit above it rather than over its send button.
 */
const composerElement = computed<HTMLElement | null>(
    () => (composer.value?.$el as HTMLElement | null) ?? null,
);

defineExpose({
    composerElement,
    focus: (): void => composer.value?.focus(),
    insertMention: (member: PersonRef): void =>
        composer.value?.insertMention(member),
    addFiles: (files: File[]): void => composer.value?.addFiles(files),
});
</script>

<template>
    <div class="contents">
        <OfflineQueueBanner
            v-if="props.isOffline && props.queuedCount > 0"
            :count="props.queuedCount"
            @discard="emit('discardQueue')"
        />

        <!-- Below `md` the line is indented to the message text column, so it
             reads as the next message rather than a stray note against the
             screen edge. -->
        <TypingIndicator
            :names="props.typingNames"
            class="mx-5 shrink-0 max-md:pl-9"
        />

        <ScheduledCountChip
            v-if="props.scheduledMessages.length > 0"
            :count="props.scheduledMessages.length"
            :channel-name="props.channel.name"
            class="mx-5 mb-2.5"
            @view="emit('viewScheduled')"
        />

        <MessageComposer
            ref="composer"
            :key="props.channel.id"
            data-tour="composer"
            :channel-name="props.channel.name"
            :placeholder="props.placeholder"
            :members="props.members"
            :has-bots="props.hasBots"
            :reply-target="props.replyTarget"
            :initial-body="props.channel.draft ?? ''"
            :messages="props.messages"
            :current-user-id="props.currentUserId"
            :pending-uuids="props.pendingUuids"
            :team-slug="props.team.slug"
            :channel-slug="props.channel.slug"
            :max-attachment-size-mb="page.props.attachments.maxSizeMb"
            :max-attachments-per-message="page.props.attachments.maxPerMessage"
            allow-schedule
            :timezone="props.timezone"
            :slash-commands="page.props.slashCommands"
            :gif-picker-enabled="page.props.gifPickerEnabled"
            :polls-enabled="page.props.pollsEnabled"
            @send="
                (body, mentions, _sendToChannel, attachmentIds, callbacks) =>
                    emit('send', body, mentions, attachmentIds, callbacks)
            "
            @command="(body, callbacks) => emit('command', body, callbacks)"
            @schedule="
                (body, mentions, sendAt) =>
                    emit('schedule', body, mentions, sendAt)
            "
            @typing="emit('typing')"
            @cancel-reply="emit('cancelReply')"
            @draft-change="(body) => emit('draftChange', body)"
            @edit="(message, body) => emit('edit', message, body)"
            @editing-change="(id) => emit('editingChange', id)"
        />
    </div>
</template>
