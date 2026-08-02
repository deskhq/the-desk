<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Archive } from '@lucide/vue';
import { computed } from 'vue';
import AvatarStack from '@/components/AvatarStack.vue';
import PresenceDot from '@/components/PresenceDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getInitials } from '@/composables/useInitials';
import { MAX_MASTHEAD_AVATARS } from '@/lib/memberAvatars';
import type { NotificationIndicator } from '@/lib/notificationIndicator';
import {
    activeMemberCount,
    dmParticipantPresence,
    presenceLabelKey,
} from '@/lib/presence';
import type { RenderedPresence } from '@/lib/presence';
import type { Channel, RosterMember } from '@/types';

/**
 * What the masthead calls the conversation it heads: its avatar treatment, its
 * name, the notification cue beside it, and the sub-lines under it.
 */
const props = defineProps<{
    channel: Channel;
    /** The team roster, which the activity readout counts. */
    members: RosterMember[];
    /** How each team member reads on the presence roster. */
    presenceFor: (userId: string) => RenderedPresence;
    /** Whether each member is in do-not-disturb, driving the crescent badge. */
    isDndFor?: (userId: string) => boolean;
    /**
     * The viewer-relative title (self-DM reads "You"); the page also feeds it to
     * `<Head>`, so it is resolved once there and passed down.
     */
    title: string;
    /** A compact header cue for a non-default notification state, or null. */
    notificationStatus: NotificationIndicator | null;
}>();

const page = usePage();

/** The other participant of a 1:1 DM, whose avatar the masthead shows. */
const dmParticipant = computed(() => props.channel.dmParticipants?.[0] ?? null);

/**
 * The avatar image for the 1:1 masthead: the other participant's, or — in a
 * self-DM, which has no other participant — the viewer's own. Null (so the
 * initials fallback shows) when that person has no avatar.
 */
const dmAvatar = computed(() =>
    dmParticipant.value
        ? (dmParticipant.value.avatar ?? null)
        : (page.props.auth.user.avatar ?? null),
);

/**
 * A 1:1 DM renders viewer-relative: the other participant's presence dot follows
 * the team roster.
 */
const dmPresence = computed<RenderedPresence>(() =>
    dmParticipantPresence(
        props.channel.dmUserId,
        props.presenceFor,
        page.props.auth.user.presence,
    ),
);

/** Whether the 1:1 counterpart shows the crescent DND badge. */
const dmDnd = computed(
    () =>
        props.channel.dmUserId != null &&
        (props.isDndFor?.(props.channel.dmUserId) ?? false),
);

/** How many of the roster are active, for the compact activity readout. */
const activeCount = computed(() =>
    activeMemberCount(props.members, props.presenceFor),
);

/** The group's participant count, including the viewer, for the subtitle. */
const groupParticipantCount = computed(
    () => (props.channel.dmParticipants?.length ?? 0) + 1,
);

/**
 * Whether the masthead has an activity readout to show.
 *
 * Below the breakpoint there is no room for the facepile, so its readout stands
 * in for it on its own sub-line under the channel name — the same numbers the
 * avatars carry on a wide viewport, in the space a phone actually has.
 */
const hasActivityReadout = computed(
    () => !props.channel.isDirect && props.members.length > 0,
);
</script>

<template>
    <div class="min-w-0 flex-1">
        <h1
            class="flex items-center gap-2 truncate font-serif text-[22px] leading-none font-semibold tracking-[-0.02em] text-foreground @2xl:text-[32px]"
        >
            <!-- A group DM shows an avatar stack of its participants; a 1:1
                 shows the other participant's avatar + presence dot; a
                 standard channel shows the "#". The name is already
                 viewer-relative (self reads "You"). -->
            <AvatarStack
                v-if="props.channel.isGroupDirect"
                data-test="masthead-group-avatars"
                :members="props.channel.dmParticipants ?? []"
                :max="MAX_MASTHEAD_AVATARS"
                size="md"
                ring-class="ring-card"
            />
            <span
                v-else-if="props.channel.isDirect"
                data-test="masthead-dm-avatar"
                class="relative inline-flex size-7 shrink-0"
            >
                <Avatar class="size-7" aria-hidden="true">
                    <AvatarImage
                        v-if="dmAvatar"
                        :src="dmAvatar"
                        :alt="props.title"
                    />
                    <AvatarFallback
                        class="text-[11px] font-semibold text-primary"
                    >
                        {{ getInitials(props.channel.name) }}
                    </AvatarFallback>
                </Avatar>
                <PresenceDot
                    data-test="masthead-dm-presence"
                    :presence="dmPresence"
                    :is-dnd="dmDnd"
                    surface-class="bg-card"
                    size="28"
                    class="ring-card"
                />
                <!-- Announced through a screen-reader-only label rather than
                     an aria-label on the role-less dot, which assistive tech
                     ignores on a bare <span>. -->
                <span class="sr-only">{{
                    dmDnd
                        ? $t('Notifications paused')
                        : $t(presenceLabelKey(dmPresence))
                }}</span>
            </span>
            <span v-else class="text-brass italic">#</span>
            <span class="truncate">{{ props.title }}</span>
            <!-- The mute / notification-level indicator sits inline with the
                 title so it reads as a property of this conversation rather
                 than floating in the meta row (which is empty for a DM with
                 no topic). -->
            <Tooltip v-if="props.notificationStatus">
                <TooltipTrigger as-child>
                    <span
                        data-test="notification-status"
                        :data-status="props.notificationStatus.status"
                        class="inline-flex shrink-0 items-center text-muted-foreground"
                        :aria-label="$t(props.notificationStatus.label)"
                    >
                        <component
                            :is="props.notificationStatus.icon"
                            class="size-4"
                        />
                    </span>
                </TooltipTrigger>
                <TooltipContent>{{
                    $t(props.notificationStatus.label)
                }}</TooltipContent>
            </Tooltip>
        </h1>

        <!-- The facepile's readout, standing in for the avatars below the
             breakpoint where they don't fit. Same numbers, one line down. -->
        <p
            v-if="hasActivityReadout"
            data-test="masthead-compact-activity"
            class="mt-1 truncate text-[11.5px] text-muted-foreground @2xl:hidden"
        >
            {{
                $t(':active of :total active', {
                    active: activeCount,
                    total: props.members.length,
                })
            }}
        </p>

        <!-- A group DM's subtitle names how many people are in the
             conversation, the viewer included. -->
        <p
            v-if="props.channel.isGroupDirect"
            data-test="masthead-group-count"
            class="mt-1 text-[13px] text-muted-foreground"
        >
            {{
                $t(':count participants, including you', {
                    count: groupParticipantCount,
                })
            }}
        </p>

        <div
            v-if="props.channel.isArchived || props.channel.topic"
            class="mt-1.5 flex items-center gap-2 text-[13px] text-muted-foreground"
        >
            <span
                v-if="props.channel.isArchived"
                class="inline-flex shrink-0 items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground"
            >
                <Archive class="size-3" />
                {{ $t('Archived') }}
            </span>

            <p
                v-if="props.channel.topic"
                data-test="masthead-topic"
                class="min-w-0 truncate"
            >
                {{ props.channel.topic }}
            </p>
        </div>
    </div>
</template>
