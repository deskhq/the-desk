<script setup lang="ts">
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/composables/useInitials';
import type { Message } from '@/types';

/** How many participant avatars to preview on a root's "N replies" affordance. */
const MAX_THREAD_AVATARS = 3;

const props = defineProps<{
    /** The thread root the summary hangs under. */
    message: Message;
    /** The last reply's time, already formatted in the viewer's zone. */
    lastReplyTime: string;
}>();

defineEmits<{
    openThread: [messageId: string];
}>();

const { getInitials } = useInitials();

const avatars = computed(() =>
    props.message.threadParticipants.slice(0, MAX_THREAD_AVATARS),
);

const extraParticipants = computed(() =>
    Math.max(0, props.message.threadParticipants.length - MAX_THREAD_AVATARS),
);
</script>

<template>
    <div class="mt-1.5 flex flex-wrap items-center gap-2">
        <Button
            variant="unstyled"
            size="none"
            type="button"
            data-test="thread-summary"
            :aria-label="
                message.threadReplyCount === 1
                    ? $t('View thread, :count reply', {
                          count: message.threadReplyCount,
                      })
                    : $t('View thread, :count replies', {
                          count: message.threadReplyCount,
                      })
            "
            class="inline-flex items-center gap-2 rounded-full border border-border bg-card px-2.5 py-1 text-left transition-colors hover:bg-muted/50"
            @click="$emit('openThread', message.id)"
        >
            <span class="flex -space-x-1">
                <Avatar
                    v-for="participant in avatars"
                    :key="participant.id"
                    class="size-4 text-[8px] ring-2 ring-card"
                    aria-hidden="true"
                >
                    <AvatarImage
                        v-if="participant.avatar"
                        :src="participant.avatar"
                        :alt="participant.name"
                    />
                    <AvatarFallback
                        class="bg-primary/10 font-semibold text-primary"
                    >
                        {{ getInitials(participant.name) }}
                    </AvatarFallback>
                </Avatar>
                <span
                    v-if="extraParticipants > 0"
                    class="flex size-4 items-center justify-center rounded-full bg-muted text-[8px] font-semibold text-muted-foreground ring-2 ring-card select-none"
                    aria-hidden="true"
                >
                    +{{ extraParticipants }}
                </span>
            </span>
            <span
                v-if="message.threadUnread"
                data-test="thread-unread-dot"
                :aria-label="$t('Unread replies')"
                class="size-2 shrink-0 rounded-full bg-rose-500"
            ></span>
            <span class="text-[12px] font-semibold text-foreground">
                {{
                    message.threadReplyCount === 1
                        ? $t(':count reply', {
                              count: message.threadReplyCount,
                          })
                        : $t(':count replies', {
                              count: message.threadReplyCount,
                          })
                }}
            </span>
            <span aria-hidden="true" class="text-[12px] text-muted-foreground"
                >→</span
            >
        </Button>
        <span
            v-if="message.threadLastReplyAt"
            class="text-[11.5px] text-muted-foreground"
        >
            {{ $t('Last reply') }}
            {{ lastReplyTime }}
        </span>
    </div>
</template>
