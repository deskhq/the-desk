<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { archive as archiveChannel } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import AddDirectMessagePeopleModal from '@/components/AddDirectMessagePeopleModal.vue';
import ArchiveChannelDialog from '@/components/channel/ArchiveChannelDialog.vue';
import ForwardMessageDialog from '@/components/ForwardMessageDialog.vue';
import LeaveChannelModal from '@/components/LeaveChannelModal.vue';
import ScheduledMessagesDialog from '@/components/ScheduledMessagesDialog.vue';
import ScheduleMessageDialog from '@/components/ScheduleMessageDialog.vue';
import { useForwardDialog } from '@/composables/useForwardDialog';
import { useReminderPicker } from '@/composables/useReminderPicker';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import type { Channel, Message, ScheduledMessage } from '@/types';
import type { ForwardTarget } from '@/types/forward';

/**
 * Every dialog the channel page can raise, and the state that decides which one
 * is open. The page opens them through the exposed handlers rather than holding
 * a flag per dialog; what each one does on confirm stays with the page's own
 * actions engine, handed in as callbacks.
 */
const props = defineProps<{
    team: { slug: string };
    channel: Channel;
    currentUserId: string;
    timezone: string | null;
    /** The viewer's own pending scheduled messages for this channel. */
    scheduledMessages: ScheduledMessage[];
    forwardMessage: (
        source: Message,
        payload: { target: ForwardTarget; note: string },
    ) => void;
    setReminder: (messageId: string, remindAt: string) => void;
    updateScheduled: (payload: {
        id: string;
        body: string;
        sendAt: string;
    }) => void;
    cancelScheduled: (id: string) => void;
}>();

const { t } = useTranslations();
const toast = useToast();

const {
    forwardTarget,
    forwardDialogOpen,
    forwardableChannels,
    forwardablePeople,
    openForward,
    submitForward,
} = useForwardDialog({
    forwardMessage: (source, payload) => props.forwardMessage(source, payload),
});

const {
    reminderCustomOpen,
    remindWith,
    openCustomReminder,
    confirmCustomReminder,
} = useReminderPicker({
    setReminder: (messageId, remindAt) =>
        props.setReminder(messageId, remindAt),
});

/** Whether the "Scheduled messages" management dialog is open. */
const scheduledDialogOpen = ref(false);

/** Drives the archive confirmation dialog opened from the channel header menu. */
const confirmingArchive = ref(false);

/** Drives the leave-channel confirmation modal opened from the header menu. */
const confirmingLeave = ref(false);

const addingPeople = ref(false);

function archive(): void {
    confirmingArchive.value = false;

    router.post(
        archiveChannel({ team: props.team.slug, channel: props.channel.slug })
            .url,
        {},
        {
            onError: () => {
                toast.error(t('Failed to archive the channel'));
            },
        },
    );
}

defineExpose({
    openForward,
    remindWith,
    openCustomReminder,
    openScheduled: (): void => {
        scheduledDialogOpen.value = true;
    },
    confirmArchive: (): void => {
        confirmingArchive.value = true;
    },
    confirmLeave: (): void => {
        confirmingLeave.value = true;
    },
    openAddPeople: (): void => {
        addingPeople.value = true;
    },
    /**
     * Close the add-people modal, so a switch to another conversation never
     * carries it over onto the wrong DM.
     */
    closeAddPeople: (): void => {
        addingPeople.value = false;
    },
});
</script>

<template>
    <div class="contents">
        <ForwardMessageDialog
            v-model:open="forwardDialogOpen"
            :message="forwardTarget"
            :channels="forwardableChannels"
            :people="forwardablePeople"
            :current-user-id="props.currentUserId"
            @submit="submitForward"
        />

        <ScheduledMessagesDialog
            v-model:open="scheduledDialogOpen"
            :scheduled-messages="props.scheduledMessages"
            :channel-name="props.channel.name"
            :timezone="props.timezone"
            @update="props.updateScheduled"
            @cancel="props.cancelScheduled"
        />

        <ScheduleMessageDialog
            v-model:open="reminderCustomOpen"
            :timezone="props.timezone"
            :title="$t('Remind me about this')"
            :confirm-label="$t('Set reminder')"
            @confirm="confirmCustomReminder"
        />

        <ArchiveChannelDialog
            v-model:open="confirmingArchive"
            :channel-name="props.channel.name"
            @confirm="archive"
        />

        <LeaveChannelModal
            v-model:open="confirmingLeave"
            :channel="props.channel"
            :team-slug="props.team.slug"
        />

        <AddDirectMessagePeopleModal
            v-if="props.channel.isDirect"
            v-model:open="addingPeople"
            :channel="props.channel"
            :team-slug="props.team.slug"
            :current-user-id="props.currentUserId"
        />
    </div>
</template>
