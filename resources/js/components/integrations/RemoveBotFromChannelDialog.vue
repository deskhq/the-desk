<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
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
import { destroy as channelDestroy } from '@/routes/teams/integrations/bots/channels';

type BotSummary = App.Data.BotData;

/** A channel the bot is a member of. */
type ChannelMembership = {
    id: string;
    name: string;
    visibility: 'public' | 'private';
};

const props = defineProps<{
    /** The workspace slug the bot belongs to. */
    team: string;
    bot: BotSummary;
}>();

/** The membership awaiting confirmation, or `null` while the dialog is closed. */
const pendingChannel = defineModel<ChannelMembership | null>('channel', {
    default: null,
});

const removeChannelForm = useForm({});

function confirmRemoveChannel(): void {
    const channel = pendingChannel.value;

    if (!channel) {
        return;
    }

    removeChannelForm.delete(
        channelDestroy({
            team: props.team,
            bot: props.bot.id,
            channel: channel.id,
        }).url,
        {
            preserveScroll: true,
            onFinish: () => (pendingChannel.value = null),
        },
    );
}
</script>

<template>
    <Dialog
        :open="pendingChannel !== null"
        @update:open="(open) => !open && (pendingChannel = null)"
    >
        <DialogContent data-test="remove-channel-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    $t('Remove :bot from :channel?', {
                        bot: bot.name,
                        channel: pendingChannel?.name ?? '',
                    })
                }}</DialogTitle>
                <DialogDescription>{{
                    $t(
                        'The bot stops posting there immediately, and any incoming webhook bound to this channel starts returning 403.',
                    )
                }}</DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <DialogClose as-child>
                    <Button variant="outline" class="rounded-full">{{
                        $t('Cancel')
                    }}</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    class="rounded-full"
                    data-test="remove-channel-confirm"
                    :disabled="removeChannelForm.processing"
                    @click="confirmRemoveChannel"
                >
                    {{ $t('Remove from channel') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
