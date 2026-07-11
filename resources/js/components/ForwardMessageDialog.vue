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
import { useInitials } from '@/composables/useInitials';
import { messageBodyPreview } from '@/lib/messageBody';
import { rankPeople } from '@/lib/peopleDirectory';
import type { Message } from '@/types';
import type { Channel } from '@/types/channels';
import type { ForwardTarget } from '@/types/forward';
import type { PersonRef } from '@/types/people';

const props = defineProps<{
    // The message being forwarded, driving the preview; null when the dialog is
    // closed (nothing is being forwarded).
    message: Message | null;
    channels: Channel[];
    // Team members, offered as DM targets (opened-or-created on forward).
    people: PersonRef[];
    // The viewer's own id, so their entry can read "You" and open a self-DM.
    currentUserId: string;
}>();

const emit = defineEmits<{
    submit: [payload: { target: ForwardTarget; note: string }];
}>();

const open = defineModel<boolean>('open', { default: false });

const { getInitials } = useInitials();

const query = ref('');
const note = ref('');
const selected = ref<ForwardTarget | null>(null);

// Only standard channels are ranked here; DMs are reached through the People
// section (which opens-or-creates the DM), so they never double up as channels.
const rankedChannels = computed(() =>
    rankChannels(
        props.channels.filter((channel) => !channel.isDirect),
        query.value,
    ),
);

const rankedPeople = computed(() =>
    rankPeople(props.people, query.value, props.currentUserId),
);

const hasResults = computed(
    () => rankedChannels.value.length > 0 || rankedPeople.value.length > 0,
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
        selected.value = null;
    }
});

function isSelected(kind: ForwardTarget['kind'], id: string): boolean {
    return selected.value?.kind === kind && selected.value.id === id;
}

function selectChannel(channel: Channel): void {
    selected.value = { kind: 'channel', id: channel.id, name: channel.name };
}

function selectPerson(person: { id: string; name: string }): void {
    selected.value = { kind: 'user', id: person.id, name: person.name };
}

function submit(): void {
    if (!selected.value) {
        return;
    }

    emit('submit', { target: selected.value, note: note.value.trim() });
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="gap-4 sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ $t('Forward message') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('Share this message with a channel or a person.') }}
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
                    {{
                        message.isDeleted ? $t('Message was deleted') : preview
                    }}
                </p>
            </div>

            <div>
                <label
                    class="mb-1 block text-[12px] font-medium text-muted-foreground"
                >
                    {{ $t('Destination') }}
                </label>
                <div
                    class="flex h-9 items-center gap-2 rounded-md border border-input px-2.5"
                >
                    <Search class="size-4 shrink-0 opacity-50" />
                    <input
                        v-model="query"
                        type="text"
                        :placeholder="$t('Search channels and people…')"
                        data-test="forward-channel-search"
                        class="h-full w-full bg-transparent text-sm outline-hidden placeholder:text-muted-foreground"
                    />
                </div>
                <div
                    class="mt-1.5 max-h-52 space-y-2 overflow-y-auto"
                    data-test="forward-channel-list"
                >
                    <div v-if="rankedChannels.length > 0">
                        <p
                            class="px-2 py-1 text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                        >
                            {{ $t('Channels') }}
                        </p>
                        <ul class="space-y-0.5">
                            <li
                                v-for="channel in rankedChannels"
                                :key="channel.id"
                            >
                                <button
                                    type="button"
                                    data-test="forward-channel-option"
                                    :aria-pressed="
                                        isSelected('channel', channel.id)
                                    "
                                    class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted"
                                    :class="
                                        isSelected('channel', channel.id)
                                            ? 'bg-muted font-medium'
                                            : ''
                                    "
                                    @click="selectChannel(channel)"
                                >
                                    <Hash class="size-4 shrink-0 opacity-60" />
                                    <span class="truncate">{{
                                        channel.name
                                    }}</span>
                                    <Check
                                        v-if="isSelected('channel', channel.id)"
                                        class="ml-auto size-4 shrink-0 text-primary"
                                    />
                                </button>
                            </li>
                        </ul>
                    </div>

                    <div v-if="rankedPeople.length > 0">
                        <p
                            class="px-2 py-1 text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                        >
                            {{ $t('People') }}
                        </p>
                        <ul class="space-y-0.5">
                            <li v-for="person in rankedPeople" :key="person.id">
                                <button
                                    type="button"
                                    data-test="forward-person-option"
                                    :aria-pressed="
                                        isSelected('user', person.id)
                                    "
                                    class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted"
                                    :class="
                                        isSelected('user', person.id)
                                            ? 'bg-muted font-medium'
                                            : ''
                                    "
                                    @click="
                                        selectPerson({
                                            id: person.id,
                                            name: person.isSelf
                                                ? $t('You')
                                                : person.name,
                                        })
                                    "
                                >
                                    <span
                                        class="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[9px] font-semibold text-primary select-none"
                                        aria-hidden="true"
                                        >{{ getInitials(person.name) }}</span
                                    >
                                    <span class="truncate">{{
                                        person.isSelf ? $t('You') : person.name
                                    }}</span>
                                    <Check
                                        v-if="isSelected('user', person.id)"
                                        class="ml-auto size-4 shrink-0 text-primary"
                                    />
                                </button>
                            </li>
                        </ul>
                    </div>

                    <p
                        v-if="!hasResults"
                        class="px-2 py-2 text-xs text-muted-foreground"
                    >
                        {{ $t('Nothing matches “:query”.', { query }) }}
                    </p>
                </div>
            </div>

            <div>
                <label
                    class="mb-1 block text-[12px] font-medium text-muted-foreground"
                >
                    {{ $t('Add a note') }}
                    <span class="font-normal text-muted-foreground/70">{{
                        $t('(optional)')
                    }}</span>
                </label>
                <textarea
                    v-model="note"
                    rows="2"
                    :placeholder="$t('Say something about this…')"
                    data-test="forward-note"
                    class="w-full resize-none rounded-md border border-input bg-background px-2.5 py-1.5 text-sm leading-[1.5] text-foreground outline-none focus:border-ring focus:ring-1 focus:ring-ring"
                ></textarea>
            </div>

            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="open = false">
                    {{ $t('Cancel') }}
                </Button>
                <Button
                    data-test="forward-submit"
                    :disabled="!selected"
                    @click="submit"
                >
                    {{ $t('Forward') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
