<script setup lang="ts">
import { computed } from 'vue';
import { useTranslations } from '@/composables/useTranslations';
import type { Message } from '@/types';

const props = defineProps<{
    /** The system-notice row (membership or channel edit) this renders. */
    message: Message;
    /**
     * Whether the timeline belongs to a direct message, so the notice reads
     * "left the conversation" rather than "left the channel".
     */
    isDirect?: boolean;
}>();

const { t } = useTranslations();

/**
 * The localized line for a system notice, rendered from its type and author in
 * the viewer's own locale — the row stores no rendered English. A topic or
 * rename notice quotes the author's own words, which ride along in the body.
 */
const text = computed(() => {
    const name = props.message.user.name;
    const subject = props.message.body;

    if (props.message.type === 'channel_renamed') {
        return t(':name renamed the channel to :channel', {
            name,
            channel: subject,
        });
    }

    if (props.message.type === 'topic_changed') {
        return subject === ''
            ? t(':name cleared the topic', { name })
            : t(':name set the topic to :topic', { name, topic: subject });
    }

    if (props.message.type === 'member_left') {
        return props.isDirect
            ? t(':name left the conversation', { name })
            : t(':name left the channel', { name });
    }

    return props.isDirect
        ? t(':name joined the conversation', { name })
        : t(':name joined the channel', { name });
});
</script>

<template>
    <!-- A system notice (member joined / left): a centered, inert line with no
         avatar, author bubble, or hover actions. -->
    <div
        :id="`message-${message.id}`"
        data-test="system-notice"
        :data-system-type="message.type"
        class="my-2 flex justify-center"
    >
        <span
            class="rounded-full bg-muted/60 px-3 py-1 text-center text-[12px] text-muted-foreground"
        >
            {{ text }}
        </span>
    </div>
</template>
