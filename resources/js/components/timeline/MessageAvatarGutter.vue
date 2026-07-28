<script setup lang="ts">
import { Bot } from '@lucide/vue';
import PresenceDot from '@/components/PresenceDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import UserHoverCard from '@/components/UserHoverCard.vue';
import { useInitials } from '@/composables/useInitials';
import type { RenderedPresence } from '@/lib/presence';
import type { MessageAuthor } from '@/types';

defineProps<{
    author: MessageAuthor;
    teamSlug: string;
    presence: RenderedPresence;
    isDnd: boolean;
    /** The group's lead timestamp, already formatted in the viewer's zone. */
    time: string;
    /** Whether the viewport is below the breakpoint, sizing the presence dot. */
    isMobile: boolean;
}>();

defineEmits<{
    mention: [member: { id: string; name: string }];
}>();

const { getInitials } = useInitials();
</script>

<template>
    <!-- The avatar gutter: 64px of centred column on desktop, a 26px avatar plus
         a 10px gap below `md`, where 64px of a 390px screen is a quarter of the
         row spent on chrome. -->
    <div
        class="flex w-16 shrink-0 flex-col items-center gap-1 pt-0.5 max-md:w-9 max-md:items-start max-md:gap-0 max-md:pt-0"
    >
        <UserHoverCard
            :team-slug="teamSlug"
            :user-id="author.id"
            :name="author.name"
            :presence="presence"
            :is-dnd="isDnd"
            @mention="(member) => $emit('mention', member)"
        >
            <div
                data-test="message-avatar"
                class="relative size-8.5 cursor-pointer max-md:size-6.5"
            >
                <!-- A bot squares off its avatar (rounded-lg vs a human's full
                     circle) and shows a glyph on the ink-stone fill, so it reads
                     as non-human at a glance; a human keeps their
                     gravatar/initials. -->
                <Avatar
                    class="size-8.5 text-[11px] max-md:size-6.5 max-md:text-[9px]"
                    :class="author.isBot ? 'rounded-lg' : ''"
                    aria-hidden="true"
                >
                    <AvatarImage
                        v-if="author.avatar && !author.isBot"
                        :src="author.avatar"
                        :alt="author.name"
                    />
                    <AvatarFallback
                        :class="
                            author.isBot
                                ? 'rounded-lg bg-muted-foreground text-background'
                                : 'bg-primary/10 font-semibold text-primary'
                        "
                    >
                        <Bot v-if="author.isBot" class="size-4.5" />
                        <template v-else>{{
                            getInitials(author.name)
                        }}</template>
                    </AvatarFallback>
                </Avatar>
                <!-- Presence is announced once per author group via the sr-only
                     text beside the name below, so this repeated dot is
                     decorative to AT. A bot has no presence, so it shows no dot. -->
                <PresenceDot
                    v-if="!author.isBot"
                    data-test="presence-dot"
                    :presence="presence"
                    :is-dnd="isDnd"
                    surface-class="bg-card"
                    :size="isMobile ? '24' : '36'"
                    class="ring-card"
                />
            </div>
        </UserHoverCard>
        <!-- The stacked time sets a tall floor on every row even for a one-word
             message, so below `md` it gives way to the inline stamp on the
             author's own line. -->
        <span
            data-test="message-group-time"
            class="font-mono text-[9.5px] text-muted-foreground max-md:hidden"
            >{{ time }}</span
        >
    </div>
</template>
