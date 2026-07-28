<script setup lang="ts">
import { Bot } from '@lucide/vue';
import { computed } from 'vue';
import PresenceDot from '@/components/PresenceDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { getInitials } from '@/composables/useInitials';
import { MAX_MASTHEAD_AVATARS, memberAvatarStack } from '@/lib/memberAvatars';
import { activeMemberCount } from '@/lib/presence';
import type { RenderedPresence } from '@/lib/presence';
import type { Mention } from '@/types';

/**
 * The channel roster's overlapping avatars and its one activity readout. A DM
 * is a fixed set, so its masthead renders no facepile at all.
 */
const props = defineProps<{
    /**
     * The team roster the page already carries for the composer, reused for the
     * overlapping member facepile.
     */
    members: Mention[];
    /** How each team member reads on the presence roster, driving every dot here. */
    presenceFor: (userId: string) => RenderedPresence;
    /** Whether each member is in do-not-disturb, driving the crescent badge. */
    isDndFor?: (userId: string) => boolean;
}>();

/** The overlapping member avatars for the masthead's right side. */
const mastheadAvatars = computed(() =>
    memberAvatarStack(props.members, MAX_MASTHEAD_AVATARS),
);

/** How many of the roster are active, as the readout beside the stack says. */
const activeCount = computed(() =>
    activeMemberCount(props.members, props.presenceFor),
);
</script>

<template>
    <span
        v-if="mastheadAvatars.visible.length > 0"
        data-test="masthead-members"
        class="hidden items-center gap-2 @2xl:flex"
    >
        <span class="flex -space-x-1.5">
            <!-- A bot in the roster squares its avatar (rounded-md vs a
                 human's circle) and shows a glyph, so it reads as
                 non-human even at this size — matching its message-row
                 treatment. A bot has no presence, so it shows no dot. -->
            <span
                v-for="member in mastheadAvatars.visible"
                :key="member.id"
                class="relative size-6"
                :title="member.name"
                aria-hidden="true"
            >
                <Avatar
                    class="size-6 text-[9px] ring-2 ring-card"
                    :class="member.isBot ? 'rounded-md' : ''"
                >
                    <AvatarImage
                        v-if="member.avatar && !member.isBot"
                        :src="member.avatar"
                        :alt="member.name"
                    />
                    <AvatarFallback
                        :class="
                            member.isBot
                                ? 'rounded-md bg-muted-foreground text-background'
                                : 'bg-primary/10 font-semibold text-primary'
                        "
                    >
                        <Bot v-if="member.isBot" class="size-3" />
                        <template v-else>{{
                            getInitials(member.name)
                        }}</template>
                    </AvatarFallback>
                </Avatar>
                <PresenceDot
                    v-if="!member.isBot"
                    data-test="masthead-member-presence"
                    :presence="props.presenceFor(member.id)"
                    :is-dnd="props.isDndFor?.(member.id) ?? false"
                    surface-class="bg-card"
                    size="24"
                    class="ring-card"
                />
            </span>
            <span
                v-if="mastheadAvatars.overflow > 0"
                class="flex size-6 items-center justify-center rounded-full bg-muted text-[9px] font-semibold text-muted-foreground ring-2 ring-card select-none"
                aria-hidden="true"
            >
                +{{ mastheadAvatars.overflow }}
            </span>
        </span>
        <!-- The one readout of the facepile, and the only place the
             member count is spelled out — away counts as present but not
             active, so the two numbers can differ. -->
        <span
            data-test="masthead-active-count"
            class="text-[11.5px] text-muted-foreground"
        >
            {{
                $t(':active of :total active', {
                    active: activeCount,
                    total: props.members.length,
                })
            }}
        </span>
    </span>
</template>
