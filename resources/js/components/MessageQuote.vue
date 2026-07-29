<script setup lang="ts">
import { computed } from 'vue';
import BotBadge from '@/components/BotBadge.vue';
import { displayAuthorName, marksAuthorAsBot } from '@/lib/authorIdentity';
import { messageBodyPreview } from '@/lib/messageBody';
import type { AuthorOverride } from '@/types';

const props = defineProps<{
    authorName: string;
    /** Whether the quoted author is a bot, so the quote badges it. */
    authorIsBot?: boolean;
    /**
     * The display identity the quoted message asked for, if any. It replaces the
     * name shown here; the bot badge rides along with it.
     */
    authorOverride?: AuthorOverride | null;
    body: string;
    isDeleted: boolean;
}>();

// A one-line, plain-text snippet of the quoted body; empty for a deleted parent,
// whose body is never sent to the client.
const preview = computed(() =>
    props.isDeleted ? '' : messageBodyPreview(props.body),
);

const displayName = computed(() =>
    displayAuthorName(props.authorName, props.authorOverride),
);

const isBot = computed(() =>
    marksAuthorAsBot(props.authorIsBot, props.authorOverride),
);
</script>

<template>
    <span
        class="flex min-w-0 items-baseline gap-1.5 border-l-2 pl-2.5 text-[12.5px] leading-tight"
        :class="isDeleted ? 'border-border' : 'border-brass'"
    >
        <span
            v-if="isDeleted"
            data-test="quote-deleted"
            class="truncate font-serif text-muted-foreground italic"
        >
            {{ $t('Original message was deleted') }}
        </span>
        <template v-else>
            <span class="shrink-0 font-semibold text-foreground/80">{{
                displayName
            }}</span>
            <BotBadge v-if="isBot" class="shrink-0 align-[1px]" />
            <span class="truncate text-muted-foreground">{{ preview }}</span>
        </template>
    </span>
</template>
