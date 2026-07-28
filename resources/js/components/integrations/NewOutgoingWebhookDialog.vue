<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FieldError from '@/components/FieldError.vue';
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
import { store as webhookStore } from '@/routes/teams/integrations/webhooks';

type Option = { value: string; label: string };
type ChannelOption = { id: string; name: string };

const props = defineProps<{
    /** The workspace slug the subscription is created in. */
    team: string;
    channels: ChannelOption[];
    eventOptions: Option[];
}>();

const open = defineModel<boolean>('open', { default: false });

const outgoingForm = useForm<{
    name: string;
    url: string;
    events: string[];
    channel_ids: string[];
}>({
    name: '',
    url: '',
    events: [],
    channel_ids: [],
});

function toggle(list: string[], value: string): void {
    const at = list.indexOf(value);

    if (at === -1) {
        list.push(value);
    } else {
        list.splice(at, 1);
    }
}

function submitOutgoing(): void {
    outgoingForm.post(webhookStore(props.team).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            outgoingForm.reset();
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="new-outgoing-dialog">
            <form @submit.prevent="submitOutgoing">
                <DialogHeader>
                    <DialogTitle>{{ $t('New subscription') }}</DialogTitle>
                    <DialogDescription>{{
                        $t(
                            'Deliver workspace events to your endpoint as signed POSTs.',
                        )
                    }}</DialogDescription>
                </DialogHeader>
                <div
                    class="flex max-h-[60vh] flex-col gap-4 overflow-y-auto py-4"
                >
                    <FormField
                        id="outgoing-name"
                        :label="$t('Name')"
                        :error="outgoingForm.errors.name"
                        v-slot="{ id }"
                    >
                        <Input
                            :id="id"
                            v-model="outgoingForm.name"
                            data-test="outgoing-name-input"
                            :placeholder="$t('Ops mirror')"
                            autocomplete="off"
                        />
                    </FormField>
                    <FormField
                        id="outgoing-url"
                        :label="$t('Endpoint URL')"
                        :error="outgoingForm.errors.url"
                        v-slot="{ id }"
                    >
                        <Input
                            :id="id"
                            v-model="outgoingForm.url"
                            data-test="outgoing-url-input"
                            type="url"
                            placeholder="https://ops.example.com/desk"
                            autocomplete="off"
                        />
                    </FormField>
                    <fieldset class="flex flex-col gap-2">
                        <legend class="text-sm font-medium">
                            {{ $t('Events') }}
                        </legend>
                        <label
                            v-for="option in eventOptions"
                            :key="option.value"
                            class="flex items-center gap-2 text-sm"
                            :data-test="`outgoing-event-${option.value}`"
                        >
                            <Checkbox
                                :model-value="
                                    outgoingForm.events.includes(option.value)
                                "
                                @update:model-value="
                                    () =>
                                        toggle(
                                            outgoingForm.events,
                                            option.value,
                                        )
                                "
                            />
                            <span class="font-mono text-xs">{{
                                option.value
                            }}</span>
                            <span class="text-muted-foreground">{{
                                option.label
                            }}</span>
                        </label>
                        <FieldError :message="outgoingForm.errors.events" />
                    </fieldset>
                    <fieldset
                        v-if="channels.length > 0"
                        class="flex flex-col gap-2"
                    >
                        <legend class="text-sm font-medium">
                            {{ $t('Channels') }}
                        </legend>
                        <span class="text-xs text-muted-foreground">{{
                            $t(
                                'Leave empty to receive events from every channel.',
                            )
                        }}</span>
                        <label
                            v-for="channel in channels"
                            :key="channel.id"
                            class="flex items-center gap-2 text-sm"
                            :data-test="`outgoing-channel-${channel.id}`"
                        >
                            <Checkbox
                                :model-value="
                                    outgoingForm.channel_ids.includes(
                                        channel.id,
                                    )
                                "
                                @update:model-value="
                                    () =>
                                        toggle(
                                            outgoingForm.channel_ids,
                                            channel.id,
                                        )
                                "
                            />
                            #{{ channel.name }}
                        </label>
                        <FieldError
                            :message="outgoingForm.errors.channel_ids"
                        />
                    </fieldset>
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
                        data-test="outgoing-create-button"
                        :disabled="outgoingForm.processing"
                        >{{ $t('Create subscription') }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
