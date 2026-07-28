<script setup lang="ts">
import { useMessageAnnouncer } from '@/composables/useMessageAnnouncer';
import { useSendFailureAnnouncer } from '@/composables/useSendFailureAnnouncer';
import type { Message } from '@/types';

/**
 * The two polite live regions the channel reads out to a screen reader: genuine
 * inbound messages, and a send that failed. The virtualized timeline itself
 * can't be a `role="log"` (its rows unmount), and a failed optimistic send rolls
 * its row back silently — so both are announced from here instead.
 */
const props = defineProps<{
    /** The live-merged timeline, watched for genuine arrivals. */
    messages: Message[];
    currentUserId: string;
}>();

const { announcement } = useMessageAnnouncer({
    messages: () => props.messages,
    currentUserId: () => props.currentUserId,
});

const { announcement: sendFailureAnnouncement, announce } =
    useSendFailureAnnouncer();

defineExpose({
    /** Announce a failed send, mirroring the toast the page raises. */
    announce,
});
</script>

<template>
    <div>
        <div
            aria-live="polite"
            aria-atomic="true"
            class="sr-only"
            data-test="message-announcer"
        >
            {{ announcement }}
        </div>

        <div
            aria-live="polite"
            aria-atomic="true"
            class="sr-only"
            data-test="send-failure-announcer"
        >
            {{ sendFailureAnnouncement }}
        </div>
    </div>
</template>
