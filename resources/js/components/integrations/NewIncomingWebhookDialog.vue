<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { store as incomingStore } from '@/routes/teams/integrations/incoming-webhooks';

type BotSummary = App.Data.BotData;
type ChannelOption = { id: string; name: string };

const props = defineProps<{
    /** The workspace slug the hook is minted in. */
    team: string;
    bots: BotSummary[];
    channels: ChannelOption[];
}>();

const open = defineModel<boolean>('open', { default: false });

const incomingForm = useForm<{
    name: string;
    channel_id: string;
    bot_id: string;
    with_signing_secret: boolean;
}>({
    name: '',
    channel_id: '',
    bot_id: '',
    with_signing_secret: false,
});

function submitIncoming(): void {
    incomingForm.post(incomingStore(props.team).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            incomingForm.reset();
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="new-incoming-dialog">
            <form @submit.prevent="submitIncoming">
                <DialogHeader>
                    <DialogTitle>{{ $t('New incoming webhook') }}</DialogTitle>
                    <DialogDescription>{{
                        $t('A secret URL that posts into one channel as a bot.')
                    }}</DialogDescription>
                </DialogHeader>
                <div class="flex flex-col gap-4 py-4">
                    <FormField
                        id="incoming-name"
                        :label="$t('Name')"
                        :error="incomingForm.errors.name"
                        v-slot="{ id }"
                    >
                        <Input
                            :id="id"
                            v-model="incomingForm.name"
                            data-test="incoming-name-input"
                            :placeholder="$t('CI alerts')"
                            autocomplete="off"
                        />
                    </FormField>
                    <FormField
                        id="incoming-channel"
                        :label="$t('Channel')"
                        :error="incomingForm.errors.channel_id"
                        v-slot="{ id }"
                    >
                        <NativeSelect
                            :id="id"
                            v-model="incomingForm.channel_id"
                            data-test="incoming-channel-select"
                            class="w-full"
                        >
                            <NativeSelectOption value="" disabled>
                                {{ $t('Select a channel') }}
                            </NativeSelectOption>
                            <NativeSelectOption
                                v-for="channel in channels"
                                :key="channel.id"
                                :value="channel.id"
                            >
                                #{{ channel.name }}
                            </NativeSelectOption>
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="incoming-bot"
                        :label="$t('Post as bot')"
                        :hint="$t('The bot must be a member of the channel.')"
                        :error="incomingForm.errors.bot_id"
                        v-slot="{ id }"
                    >
                        <NativeSelect
                            :id="id"
                            v-model="incomingForm.bot_id"
                            data-test="incoming-bot-select"
                            class="w-full"
                        >
                            <NativeSelectOption value="" disabled>
                                {{ $t('Select a bot') }}
                            </NativeSelectOption>
                            <NativeSelectOption
                                v-for="bot in bots"
                                :key="bot.id"
                                :value="bot.id"
                            >
                                {{ bot.name }}
                            </NativeSelectOption>
                        </NativeSelect>
                    </FormField>
                    <label
                        class="flex items-center gap-2 text-sm"
                        data-test="incoming-signing-toggle"
                    >
                        <Checkbox
                            :model-value="incomingForm.with_signing_secret"
                            @update:model-value="
                                (value) =>
                                    (incomingForm.with_signing_secret =
                                        value === true)
                            "
                        />
                        {{ $t('Also mint an HMAC signing secret') }}
                    </label>
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
                        data-test="incoming-create-button"
                        :disabled="incomingForm.processing"
                        >{{ $t('Create webhook') }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
