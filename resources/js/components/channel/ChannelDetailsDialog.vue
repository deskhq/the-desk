<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { update } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import FormField from '@/components/FormField.vue';
import SafeHtml from '@/components/SafeHtml.vue';
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
import { Input } from '@/components/ui/input';
import { renderMessageBody } from '@/lib/messageBody';
import type { Channel } from '@/types';

/**
 * What a channel says about itself: its name, its one-line topic, and the
 * long-form description of what it is for — and, for whoever is allowed to
 * change them, the form that edits all three.
 *
 * It is deliberately one modal in two modes rather than a settings page: the
 * read view is where the description is actually legible (the masthead only has
 * room for the topic), and the edit form is a step away from it.
 */
const props = defineProps<{
    channel: Channel;
    teamSlug: string;
    /** Whether the viewer may reword the topic and description (any member). */
    canEdit: boolean;
    /** Whether the viewer may also rename it (the creator or a team Admin+). */
    canRename: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

/**
 * The server's own caps (`UpdateChannelRequest`), mirrored onto the controls so
 * the field stops accepting characters the save would only reject. The server
 * rule stays the authority; this is courtesy, not validation.
 */
const MAX_TOPIC_LENGTH = 255;
const MAX_DESCRIPTION_LENGTH = 1500;

/** Whether the modal is showing the edit form rather than the details. */
const editing = ref(false);

/**
 * Remounts the form so its uncontrolled inputs pick up the current channel
 * again — after a save, a cancel, or a switch to another channel.
 */
const formKey = ref(0);

/** The description as safe HTML: basic inline Markdown plus autolinked URLs. */
const descriptionHtml = computed(() =>
    renderMessageBody(props.channel.description ?? ''),
);

function stopEditing(): void {
    editing.value = false;
    formKey.value += 1;
}

watch(open, (isOpen) => {
    if (!isOpen) {
        stopEditing();
    }
});

// A channel switch can happen under an open modal; never leave it showing one
// channel's details with another's edits half-typed.
watch(() => props.channel.id, stopEditing);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-test="channel-details-dialog">
            <DialogHeader>
                <DialogTitle>
                    <span aria-hidden="true" class="text-brass italic">#</span>
                    {{ props.channel.name }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        editing
                            ? $t('Edit what this channel is called and about.')
                            : $t('What this channel is called and about.')
                    }}
                </DialogDescription>
            </DialogHeader>

            <Form
                v-if="editing"
                :key="formKey"
                v-bind="
                    update.form({
                        team: props.teamSlug,
                        channel: props.channel.slug,
                    })
                "
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="stopEditing"
            >
                <div class="grid gap-4">
                    <FormField
                        v-if="props.canRename"
                        id="channel-name"
                        :label="$t('Name')"
                        :error="errors.name"
                        v-slot="{ id }"
                    >
                        <div class="relative">
                            <span
                                aria-hidden="true"
                                class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 font-serif text-[15px] text-brass italic"
                                >#</span
                            >
                            <Input
                                :id="id"
                                name="name"
                                data-test="channel-details-name"
                                :default-value="props.channel.name"
                                class="pl-7"
                                required
                            />
                        </div>
                    </FormField>

                    <FormField
                        id="channel-topic"
                        :label="$t('Topic')"
                        :hint="$t('One line, shown beside the channel name.')"
                        :error="errors.topic"
                        v-slot="{ id }"
                    >
                        <Input
                            :id="id"
                            name="topic"
                            data-test="channel-details-topic"
                            :default-value="props.channel.topic ?? ''"
                            :placeholder="$t('What\'s this channel about?')"
                            :maxlength="MAX_TOPIC_LENGTH"
                        />
                    </FormField>

                    <FormField
                        id="channel-description"
                        :label="$t('Description')"
                        :hint="$t('Links and basic formatting are supported.')"
                        :error="errors.description"
                        v-slot="{ id }"
                    >
                        <textarea
                            :id="id"
                            name="description"
                            rows="5"
                            data-test="channel-details-description"
                            :value="props.channel.description ?? ''"
                            :placeholder="
                                $t('What is this channel for, in full?')
                            "
                            :maxlength="MAX_DESCRIPTION_LENGTH"
                            class="w-full resize-none rounded-lg border border-input bg-popover px-3 py-2.5 text-base leading-[1.5] text-foreground outline-none focus:border-ring focus:ring-1 focus:ring-ring md:text-[13.5px]"
                        ></textarea>
                    </FormField>
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        data-test="channel-details-cancel"
                        @click="stopEditing"
                    >
                        {{ $t('Cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        data-test="channel-details-save"
                        :disabled="processing"
                    >
                        {{ $t('Save changes') }}
                    </Button>
                </DialogFooter>
            </Form>

            <template v-else>
                <dl class="grid gap-4 text-[13.5px]">
                    <div class="grid gap-1">
                        <dt
                            class="text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                        >
                            {{ $t('Topic') }}
                        </dt>
                        <dd
                            data-test="channel-details-topic-value"
                            :class="
                                props.channel.topic
                                    ? 'text-foreground'
                                    : 'text-muted-foreground italic'
                            "
                        >
                            {{ props.channel.topic || $t('No topic set') }}
                        </dd>
                    </div>

                    <div class="grid gap-1">
                        <dt
                            class="text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                        >
                            {{ $t('Description') }}
                        </dt>
                        <dd
                            v-if="props.channel.description"
                            data-test="channel-details-description-value"
                            class="leading-[1.6] break-words text-foreground [&_a]:text-primary [&_a]:underline"
                        >
                            <SafeHtml
                                :html="descriptionHtml"
                                variant="messageBody"
                            />
                        </dd>
                        <dd
                            v-else
                            data-test="channel-details-description-value"
                            class="text-muted-foreground italic"
                        >
                            {{ $t('No description yet') }}
                        </dd>
                    </div>
                </dl>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">{{ $t('Close') }}</Button>
                    </DialogClose>
                    <Button
                        v-if="props.canEdit"
                        type="button"
                        data-test="channel-details-edit"
                        @click="editing = true"
                    >
                        {{ $t('Edit') }}
                    </Button>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>
</template>
