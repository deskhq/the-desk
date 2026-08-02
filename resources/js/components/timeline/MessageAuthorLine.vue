<script setup lang="ts">
import { computed } from 'vue';
import BotBadge from '@/components/BotBadge.vue';
import UserHoverCard from '@/components/UserHoverCard.vue';
import UserStatusEmoji from '@/components/UserStatusEmoji.vue';
import { displayAuthorName, marksAuthorAsBot } from '@/lib/authorIdentity';
import { presenceLabelKey } from '@/lib/presence';
import type { RenderedPresence } from '@/lib/presence';
import type { AuthorOverride, MessageAuthor } from '@/types';

const props = defineProps<{
    author: MessageAuthor;
    /**
     * The display identity the run's messages asked for, if any. It replaces the
     * name shown here; the bot badge below rides along regardless.
     */
    authorOverride?: AuthorOverride | null;
    /**
     * The credential behind the run, when the viewer is one of the people who
     * could revoke it. Passed straight to the hover card, which names it.
     */
    postedVia?: App.Data.MessageCredentialData | null;
    teamSlug: string;
    presence: RenderedPresence;
    isDnd: boolean;
    /** The group's lead timestamp, already formatted in the viewer's zone. */
    time: string;
}>();

defineEmits<{
    mention: [member: { id: string; name: string }];
}>();

const displayName = computed(() =>
    displayAuthorName(props.author.name, props.authorOverride),
);

const isBot = computed(() =>
    marksAuthorAsBot(props.author.isBot, props.authorOverride),
);

/**
 * The account behind a displayed name that isn't its own, so the hover card can
 * name it ("via Deploy Bot") and a suspicious reader can always reach the real
 * account. Null when the row shows the author's own name.
 */
const viaName = computed(() =>
    displayName.value === props.author.name ? null : props.author.name,
);
</script>

<template>
    <span>
        <UserHoverCard
            :team-slug="teamSlug"
            :user-id="author.id"
            :name="author.name"
            :via-name="viaName"
            :credential="postedVia"
            :presence="presence"
            :is-dnd="isDnd"
            @mention="(member) => $emit('mention', member)"
        >
            <span
                data-test="message-author-name"
                class="cursor-pointer text-[14px] font-semibold text-foreground hover:underline"
                >{{ displayName }}</span
            >
        </UserHoverCard>
        <!-- The author's status emoji rides beside their name; the full text
             lives on the hover card. -->
        <UserStatusEmoji
            :status="author.status"
            :name="displayName"
            class="ml-1.5 align-[-1px] text-[13px]"
        />
        <!-- The uppercase "Bot" tag rides beside a bot author's name; a bot has
             no presence, so it replaces the Online/Offline announcement rather
             than adding to it. -->
        <BotBadge v-if="isBot" class="ml-1.5 align-[1px]" />
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
