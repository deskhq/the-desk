<script setup lang="ts">
import { Forward } from '@lucide/vue';
import { computed } from 'vue';
import BotBadge from '@/components/BotBadge.vue';
import SafeHtml from '@/components/SafeHtml.vue';
import { useCustomEmojis } from '@/composables/useCustomEmojis';
import { useUserGroups } from '@/composables/useUserGroups';
import { displayAuthorName, marksAuthorAsBot } from '@/lib/authorIdentity';
import { renderMessageBody } from '@/lib/messageBody';
import type { AuthorOverride, Mention } from '@/types';

const props = defineProps<{
    authorName: string;
    /** Whether the forwarded author is a bot, so the attribution badges it. */
    authorIsBot?: boolean;
    /**
     * The display identity the forwarded message asked for, if any. It replaces
     * the name shown here; the bot badge rides along with it.
     */
    authorOverride?: AuthorOverride | null;
    /** Null when the source is a direct message, which has no channel name. */
    channelName: string | null;
    body: string;
    isDeleted: boolean;
    mentions: Mention[];
}>();

const displayName = computed(() =>
    displayAuthorName(props.authorName, props.authorOverride),
);

const isBot = computed(() =>
    marksAuthorAsBot(props.authorIsBot, props.authorOverride),
);

const { map: customEmojis } = useCustomEmojis();
const { groups: userGroups } = useUserGroups();

/**
 * The forwarded body, rendered with its own mentions; empty for a deleted
 * source, whose body is never sent to the client.
 */
const rendered = computed(() =>
    props.isDeleted
        ? ''
        : renderMessageBody(
              props.body,
              props.mentions,
              customEmojis.value,
              userGroups.value,
          ),
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
                <span class="text-muted-foreground">#</span>{{ channelName }}
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
                class="font-serif text-[13px] text-muted-foreground italic"
            >
                {{ $t('Original message was deleted') }}
            </p>
            <template v-else>
                <p
                    class="flex items-baseline gap-1.5 text-[12.5px] font-semibold text-foreground"
                >
                    <span class="min-w-0 truncate">{{ displayName }}</span>
                    <BotBadge v-if="isBot" class="shrink-0 align-[1px]" />
                </p>
                <p
                    class="mt-0.5 text-[13.5px] leading-[1.5] break-words whitespace-pre-wrap text-foreground/85"
                >
                    <SafeHtml :html="rendered" variant="messageBody" />
                </p>
            </template>
        </div>
    </div>
</template>
