<script setup lang="ts">
import MessageQuote from '@/components/MessageQuote.vue';
import { Button } from '@/components/ui/button';
import type { MessageReply } from '@/types';

defineProps<{
    /** The parent message this row answers. */
    replyTo: MessageReply;
    /**
     * The row's own bleed geometry, letting the card run the full timeline width
     * below `md` — passed in because the offsets belong to the row, not the card.
     */
    bleedClass: string;
}>();

defineEmits<{
    /** Bring the replied-to message into view. */
    jump: [messageId: string];
}>();
</script>

<template>
    <Button
        variant="unstyled"
        size="none"
        type="button"
        data-test="message-quote"
        :aria-label="
            $t('Jump to replied message from :author', {
                author: replyTo.authorName,
            })
        "
        class="mt-0.5 flex max-w-full items-center rounded pr-1 text-left hover:opacity-80 max-md:border-y max-md:border-border max-md:bg-card max-md:py-1.5 max-md:pr-5 max-md:pl-5"
        :class="bleedClass"
        @click="$emit('jump', replyTo.id)"
    >
        <MessageQuote
            :author-name="replyTo.authorName"
            :body="replyTo.body"
            :is-deleted="replyTo.isDeleted"
        />
    </Button>
</template>
