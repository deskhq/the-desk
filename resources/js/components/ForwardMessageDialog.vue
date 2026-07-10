<script setup lang="ts">
import { Check, Hash, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { rankChannels } from '@/composables/quickSwitcher';
import { messageBodyPreview } from '@/lib/messageBody';
import type { Message } from '@/types';
import type { Channel } from '@/types/channels';

const props = defineProps<{
    // The message being forwarded, driving the preview; null when the dialog is
    // closed (nothing is being forwarded).
    message: Message | null;
    channels: Channel[];
}>();

const emit = defineEmits<{
    submit: [payload: { channel: Channel; note: string }];
}>();

const open = defineModel<boolean>('open', { default: false });

const query = ref('');
const note = ref('');
const selectedChannelId = ref<string | null>(null);

// Fuzzy-ranked channel matches for the picker, reusing the quick switcher's
// scorer so the ordering matches everywhere channels are searched.
const rankedChannels = computed(() =>
    rankChannels(props.channels, query.value),
);

const selectedChannel = computed(
    () =>
        props.channels.find(
            (channel) => channel.id === selectedChannelId.value,
        ) ?? null,
);

// A one-line snippet of the message being forwarded, empty for a deleted source.
const preview = computed(() =>
    props.message && !props.message.isDeleted
        ? messageBodyPreview(props.message.body)
        : '',
);

// Reset the picker every time it opens so it never reappears with a stale query,
// note, or selection.
watch(open, (isOpen) => {
    if (isOpen) {
        query.value = '';
        note.value = '';
        selectedChannelId.value = null;
    }
});

function selectChannel(channel: Channel): void {
    selectedChannelId.value = channel.id;
}

function submit(): void {
    const channel = selectedChannel.value;

    if (!channel) {
        return;
    }

    emit('submit', { channel, note: note.value.trim() });
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="gap-4 sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Forward message</DialogTitle>
                <DialogDescription>
                    Share this message to another channel you're in.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="message"
                class="rounded-r-lg border-l-2 border-brass bg-muted/30 px-3 py-2"
            >
                <p class="text-[12.5px] font-semibold text-foreground">
                    {{ message.user.name }}
                </p>
                <p
                    class="mt-0.5 line-clamp-2 text-[13px] text-foreground/80"
                    :class="message.isDeleted ? 'italic' : ''"
                >
                    {{ message.isDeleted ? 'Message was deleted' : preview }}
                </p>
            </div>

            <div>
                <label
                    class="mb-1 block text-[12px] font-medium text-muted-foreground"
                >
                    Channel
                </label>
                <div
                    class="flex h-9 items-center gap-2 rounded-md border border-input px-2.5"
                >
                    <Search class="size-4 shrink-0 opacity-50" />
                    <input
                        v-model="query"
                        type="text"
                        placeholder="Search channels…"
                        data-test="forward-channel-search"
                        class="h-full w-full bg-transparent text-sm outline-hidden placeholder:text-muted-foreground"
                    />
                </div>
                <ul
                    class="mt-1.5 max-h-44 space-y-0.5 overflow-y-auto"
                    data-test="forward-channel-list"
                >
                    <li v-for="channel in rankedChannels" :key="channel.id">
                        <button
                            type="button"
                            data-test="forward-channel-option"
                            :aria-pressed="channel.id === selectedChannelId"
                            class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted"
                            :class="
                                channel.id === selectedChannelId
                                    ? 'bg-muted font-medium'
                                    : ''
                            "
                            @click="selectChannel(channel)"
                        >
                            <Hash class="size-4 shrink-0 opacity-60" />
                            <span class="truncate">{{ channel.name }}</span>
                            <Check
                                v-if="channel.id === selectedChannelId"
                                class="ml-auto size-4 shrink-0 text-primary"
                            />
                        </button>
                    </li>
                    <li
                        v-if="rankedChannels.length === 0"
                        class="px-2 py-2 text-xs text-muted-foreground"
                    >
                        No channels match “{{ query }}”.
                    </li>
                </ul>
            </div>

            <div>
                <label
                    class="mb-1 block text-[12px] font-medium text-muted-foreground"
                >
                    Add a note
                    <span class="font-normal text-muted-foreground/70"
                        >(optional)</span
                    >
                </label>
                <textarea
                    v-model="note"
                    rows="2"
                    placeholder="Say something about this…"
                    data-test="forward-note"
                    class="w-full resize-none rounded-md border border-input bg-background px-2.5 py-1.5 text-sm leading-[1.5] text-foreground outline-none focus:border-ring focus:ring-1 focus:ring-ring"
                ></textarea>
            </div>

            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="open = false">
                    Cancel
                </Button>
                <Button
                    data-test="forward-submit"
                    :disabled="!selectedChannel"
                    @click="submit"
                >
                    Forward
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
