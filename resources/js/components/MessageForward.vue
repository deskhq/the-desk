<script setup lang="ts">
import { Forward } from '@lucide/vue';
import { computed } from 'vue';
import { useCustomEmojis } from '@/composables/useCustomEmojis';
import { renderMessageBody } from '@/lib/messageBody';
import type { Mention } from '@/types';

const props = defineProps<{
    authorName: string;
    // Null when the source is a direct message, which has no channel name.
    channelName: string | null;
    body: string;
    isDeleted: boolean;
    mentions: Mention[];
}>();

const { map: customEmojis } = useCustomEmojis();

// The forwarded body, rendered with its own mentions; empty for a deleted
// source, whose body is never sent to the client.
const rendered = computed(() =>
    props.isDeleted
        ? ''
        : renderMessageBody(props.body, props.mentions, customEmojis.value),
);
</script>

<template>
    <div class="mt-1 max-w-full">
        <p
            class="flex items-center gap-1.5 text-[11.5px] font-medium text-muted-foreground"
        >
            <Forward class="size-3 shrink-0" aria-hidden="true" />
            <span v-if="channelName">
                {{ $t('Forwarded from') }}
                <span class="text-muted-foreground/70">#</span>{{ channelName }}
            </span>
            <span v-else>
                {{ $t('Forwarded from a direct message') }}
            </span>
        </p>
        <div
            class="mt-1 rounded-md border-l-2 border-border bg-muted/30 py-1.5 pr-2 pl-2.5"
        >
            <p
                v-if="isDeleted"
                data-test="forward-deleted"
                class="font-serif text-[13px] text-muted-foreground/70 italic"
            >
                {{ $t('Original message was deleted') }}
            </p>
            <template v-else>
                <p class="text-[12.5px] font-semibold text-foreground">
                    {{ authorName }}
                </p>
                <p
                    class="mt-0.5 text-[13.5px] leading-[1.5] break-words whitespace-pre-wrap text-foreground/85"
                >
                    <span v-html="rendered"></span>
                </p>
            </template>
        </div>
    </div>
</template>
