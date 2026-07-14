<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { leave as leaveChannelAction } from '@/actions/App/Http/Controllers/Channels/ChannelController';
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
import type { Channel } from '@/types';

const props = defineProps<{
    channel: Channel;
    teamSlug: string;
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const processing = ref(false);

// A private channel is invite-only, so leaving warns that returning needs an
// invite; a public channel can simply be re-joined from the browse view.
const isPrivate = computed(() => props.channel.visibility === 'private');

// A group DM leaves the sidebar and posts a "left the conversation" note for the
// others; it reads as leaving a conversation, not a named channel.
const isDirect = computed(() => props.channel.isDirect);

const leaveChannel = () => {
    router.post(
        leaveChannelAction({
            team: props.teamSlug,
            channel: props.channel.slug,
        }).url,
        {},
        {
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => emit('update:open', false),
        },
    );
};
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    isDirect
                        ? $t('Leave this conversation?')
                        : $t('Leave #:channel', { channel: props.channel.name })
                }}</DialogTitle>
                <DialogDescription>
                    <template v-if="isDirect">
                        {{
                            $t(
                                'You’ll stop receiving new messages and the conversation leaves your sidebar. The others keep the full history, and a note tells them you left. You can be re-added any time.',
                            )
                        }}
                    </template>
                    <template v-else-if="isPrivate">
                        {{
                            $t(
                                'This channel is private, so re-joining requires an invite from a member.',
                            )
                        }}
                    </template>
                    <template v-else>
                        {{
                            $t(
                                'You can re-join this public channel any time from the channel browser.',
                            )
                        }}
                    </template>
                </DialogDescription>
            </DialogHeader>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary"> {{ $t('Cancel') }} </Button>
                </DialogClose>

                <Button
                    data-test="leave-channel-confirm"
                    variant="destructive"
                    :disabled="processing"
                    @click="leaveChannel"
                >
                    {{
                        isDirect
                            ? $t('Leave conversation')
                            : $t('Leave channel')
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
