<script setup lang="ts">
import UserHoverCard from '@/components/UserHoverCard.vue';
import UserStatusEmoji from '@/components/UserStatusEmoji.vue';
import { presenceLabelKey } from '@/lib/presence';
import type { RenderedPresence } from '@/lib/presence';
import type { MessageAuthor } from '@/types';

defineProps<{
    author: MessageAuthor;
    teamSlug: string;
    presence: RenderedPresence;
    isDnd: boolean;
    /** The group's lead timestamp, already formatted in the viewer's zone. */
    time: string;
}>();

defineEmits<{
    mention: [member: { id: string; name: string }];
}>();
</script>

<template>
    <span>
        <UserHoverCard
            :team-slug="teamSlug"
            :user-id="author.id"
            :name="author.name"
            :presence="presence"
            :is-dnd="isDnd"
            @mention="(member) => $emit('mention', member)"
        >
            <span
                data-test="message-author-name"
                class="cursor-pointer text-[14px] font-semibold text-foreground hover:underline"
                >{{ author.name }}</span
            >
        </UserHoverCard>
        <!-- The author's status emoji rides beside their name; the full text
             lives on the hover card. -->
        <UserStatusEmoji
            :status="author.status"
            :name="author.name"
            class="ml-1.5 align-[-1px] text-[13px]"
        />
        <!-- The uppercase "Bot" tag rides beside a bot author's name; a bot has
             no presence, so it replaces the Online/Offline announcement rather
             than adding to it. -->
        <span
            v-if="author.isBot"
            data-test="author-bot-badge"
            class="ml-1.5 inline-flex items-center rounded border border-border px-1.5 align-[1px] text-[9px] font-bold tracking-[0.08em] text-muted-foreground uppercase"
            >{{ $t('Bot') }}</span
        >
        <span v-else class="sr-only">{{ $t(presenceLabelKey(presence)) }}</span>
        <!-- Below `md` the group's time rides beside the author name instead of
             stacking under the avatar, where a 36px gutter has no room for it.
             Hidden from AT on both layouts: each row's accessible name already
             carries its own time. -->
        <span
            data-test="message-group-time-inline"
            aria-hidden="true"
            class="ml-1.5 font-mono text-[10.5px] text-muted-foreground md:hidden"
            >{{ time }}</span
        >
    </span>
</template>
