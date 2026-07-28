<script setup lang="ts">
import { X } from '@lucide/vue';
import MessageQuote from '@/components/MessageQuote.vue';
import { Button } from '@/components/ui/button';
import type { Message } from '@/types';

defineProps<{
    /** The message being answered. */
    target: Message;
}>();

defineEmits<{
    /** Drop the reply context and compose a plain message instead. */
    cancel: [];
}>();
</script>

<template>
    <div
        data-test="reply-preview"
        class="mb-2 flex items-center gap-2 rounded-2xl border border-input bg-muted/40 px-3.5 py-2 max-md:py-1"
    >
        <span class="min-w-0 flex-1">
            <MessageQuote
                :author-name="target.user.name"
                :body="target.body"
                :is-deleted="target.isDeleted"
            />
        </span>
        <Button
            variant="unstyled"
            size="none"
            type="button"
            data-test="reply-preview-dismiss"
            :aria-label="$t('Cancel reply')"
            class="flex shrink-0 items-center justify-center rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground max-md:size-11"
            @click="$emit('cancel')"
        >
            <X class="size-3.5 max-md:size-4.5" />
        </Button>
    </div>
</template>
