<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import FormField from '@/components/FormField.vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store as channelStore } from '@/routes/teams/integrations/bots/channels';

type BotSummary = App.Data.BotData;

/** A channel the bot could be added to. */
type ChannelMembership = {
    id: string;
    name: string;
    visibility: 'public' | 'private';
};

const props = defineProps<{
    /** The workspace slug the bot belongs to. */
    team: string;
    bot: BotSummary;
    /** The team's standard channels the bot can still be added to. */
    channels: ChannelMembership[];
}>();

const open = defineModel<boolean>('open', { default: false });

const addChannelForm = useForm<{ channel_id: string }>({ channel_id: '' });

function submitAddChannel(): void {
    addChannelForm.post(
        channelStore({ team: props.team, bot: props.bot.id }).url,
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                addChannelForm.reset();
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="add-channel-dialog">
            <form @submit.prevent="submitAddChannel">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('Add :bot to a channel', { bot: bot.name })
                    }}</DialogTitle>
                    <DialogDescription>{{
                        $t('The bot can post in every channel it belongs to.')
                    }}</DialogDescription>
                </DialogHeader>
                <div class="py-4">
                    <FormField
                        id="add-channel-select"
                        :label="$t('Channel')"
                        :error="addChannelForm.errors.channel_id"
                        v-slot="{ id }"
                    >
                        <Select v-model="addChannelForm.channel_id">
                            <SelectTrigger
                                :id="id"
                                data-test="add-channel-select"
                                class="w-full"
                            >
                                <SelectValue
                                    :placeholder="$t('Select a channel')"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="channel in channels"
                                    :key="channel.id"
                                    :value="channel.id"
                                    :data-test="`add-channel-option-${channel.id}`"
                                >
                                    <span class="flex items-center gap-2">
                                        <Lock
                                            v-if="
                                                channel.visibility === 'private'
                                            "
                                            class="size-3.5 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <span
                                            v-else
                                            aria-hidden="true"
                                            class="text-brass"
                                            >#</span
                                        >
                                        {{ channel.name }}
                                    </span>
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </FormField>
                </div>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button
                            type="button"
                            variant="outline"
                            class="rounded-full"
                            >{{ $t('Cancel') }}</Button
                        >
                    </DialogClose>
                    <Button
                        type="submit"
                        class="rounded-full"
                        data-test="add-channel-submit"
                        :disabled="
                            addChannelForm.processing ||
                            !addChannelForm.channel_id
                        "
                        >{{ $t('Add to channel') }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
