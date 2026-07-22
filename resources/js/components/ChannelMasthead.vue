<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Archive,
    Bot,
    Check,
    EllipsisVertical,
    LogOut,
    Pin,
    Search,
    Star,
    UserPlus,
} from '@lucide/vue';
import type { AcceptableValue } from 'reka-ui';
import { computed } from 'vue';
import { index as searchMessages } from '@/actions/App/Http/Controllers/Channels/SearchController';
import AvatarStack from '@/components/AvatarStack.vue';
import PresenceDot from '@/components/PresenceDot.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { ConnectionPill } from '@/composables/useConnectionState';
import { getInitials } from '@/composables/useInitials';
import { memberAvatarStack } from '@/lib/memberAvatars';
import type { NotificationIndicator } from '@/lib/notificationIndicator';
import { dmParticipantPresence, presenceLabelKey } from '@/lib/presence';
import type { RenderedPresence } from '@/lib/presence';
import type {
    Channel,
    Mention,
    NotificationLevel,
    NotificationLevelOption,
} from '@/types';

const props = defineProps<{
    channel: Channel;
    teamSlug: string;
    /**
     * The team roster the page already carries for the composer, reused for the
     * overlapping member facepile.
     */
    members: Mention[];
    /** How each team member reads on the presence roster, driving every dot here. */
    presenceFor: (userId: string) => RenderedPresence;
    /**
     * The viewer-relative title (self-DM reads "You"); the page also feeds it to
     * `<Head>`, so it is resolved once there and passed down.
     */
    title: string;
    canManagePreferences: boolean;
    canArchive: boolean;
    /**
     * Whether the viewer may leave the channel — a member of a standard channel
     * that isn't #general, or of a group DM. Drives the "Leave" menu item.
     */
    canLeave: boolean;
    /**
     * Whether the viewer may add people to this DM (a member of any DM). Drives
     * the masthead's "Add people" button.
     */
    canAddPeople: boolean;
    notificationLevels: NotificationLevelOption[];
    starred: boolean;
    muted: boolean;
    /**
     * The channel's pinned-message count, driving the pins button's badge. Kept
     * live from the `MessagePinned` broadcast by the page.
     */
    pinCount: number;
    notificationLevel: NotificationLevel;
    /** A compact header cue for a non-default notification state, or null. */
    notificationStatus: NotificationIndicator | null;
    /**
     * The realtime connection cue: a reconnecting pill, a transient back-online
     * confirmation, or null when the socket is steadily connected.
     */
    connectionPill?: ConnectionPill;
}>();

const emit = defineEmits<{
    toggleStar: [];
    notificationLevelChange: [value: AcceptableValue];
    muteChange: [value: boolean];
    archive: [];
    leave: [];
    addPeople: [];
    openPins: [];
}>();

/**
 * How many member avatars the masthead shows before collapsing the rest into a
 * single "+N" overflow chip.
 */
const MAX_MASTHEAD_AVATARS = 3;

/** The overlapping member avatars for the masthead's right side. */
const mastheadAvatars = computed(() =>
    memberAvatarStack(props.members, MAX_MASTHEAD_AVATARS),
);

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
 * the team roster. A DM is a fixed set, so the "who's in the channel" facepile is
 * meaningless and hidden.
 */
const dmPresence = computed<RenderedPresence>(() =>
    dmParticipantPresence(
        props.channel.dmUserId,
        props.presenceFor,
        page.props.auth.user.presence,
    ),
);

/**
 * How many of the channel's members are active right now.
 *
 * Away counts as present but not active, which is the whole point of the third
 * state: the readout answers "who could answer me now", not "who has the tab
 * open".
 */
const activeMemberCount = computed(
    () =>
        props.members.filter(
            (member) => props.presenceFor(member.id) === 'active',
        ).length,
);

/** The group's participant count, including the viewer, for the subtitle. */
const groupParticipantCount = computed(
    () => (props.channel.dmParticipants?.length ?? 0) + 1,
);
</script>

<template>
    <header
        class="flex shrink-0 items-end gap-4 border-b border-border px-7 pt-5 pb-3.5"
    >
        <SidebarTrigger
            class="mb-1 -ml-1.5 size-8 shrink-0 text-muted-foreground md:hidden"
        />

        <div class="min-w-0 flex-1">
            <h1
                class="flex items-center gap-2 truncate font-serif text-[32px] leading-none font-semibold tracking-[-0.02em] text-foreground"
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
                        surface-class="bg-card"
                        class="absolute -right-0.5 -bottom-0.5 size-2.5 ring-2 ring-card"
                    />
                    <!-- Announced through a screen-reader-only label rather than
                         an aria-label on the role-less dot, which assistive tech
                         ignores on a bare <span>. -->
                    <span class="sr-only">{{
                        $t(presenceLabelKey(dmPresence))
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

                <p v-if="props.channel.topic" class="min-w-0 truncate">
                    {{ props.channel.topic }}
                </p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-3 pb-1">
            <!-- Realtime connection cue: a quiet amber pill while reconnecting,
                 flipping to a brief green confirmation once the socket recovers.
                 The app stays fully usable throughout. -->
            <span
                v-if="props.connectionPill === 'reconnecting'"
                data-test="connection-reconnecting"
                role="status"
                class="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[11.5px] font-semibold text-amber-700 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-500"
            >
                <span
                    class="size-1.5 animate-pulse rounded-full bg-amber-500"
                />
                {{ $t('Reconnecting')
                }}<span
                    aria-hidden="true"
                    class="inline-flex w-2.5 justify-start"
                    ><span class="animate-pulse [animation-delay:0ms]">.</span
                    ><span class="animate-pulse [animation-delay:200ms]">.</span
                    ><span class="animate-pulse [animation-delay:400ms]"
                        >.</span
                    ></span
                >
            </span>
            <span
                v-else-if="props.connectionPill === 'back-online'"
                data-test="connection-back-online"
                role="status"
                class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[11.5px] font-semibold text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-500"
            >
                <Check class="size-3" />
                {{ $t('Back online') }}
            </span>

            <span
                v-if="
                    !props.channel.isDirect &&
                    mastheadAvatars.visible.length > 0
                "
                data-test="masthead-members"
                class="flex items-center gap-2"
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
                            surface-class="bg-card"
                            class="absolute -right-0.5 -bottom-0.5 size-2 ring-2 ring-card"
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
                            active: activeMemberCount,
                            total: props.members.length,
                        })
                    }}
                </span>
            </span>

            <!-- Add people: opens the picker that grows this DM into (or reuses)
                 a group conversation. Only shown to a member of a DM. -->
            <Button
                v-if="props.canAddPeople"
                variant="outline"
                size="sm"
                type="button"
                data-test="masthead-add-people"
                class="h-8 gap-1.5 rounded-full px-4 text-[12.5px] font-semibold"
                @click="emit('addPeople')"
            >
                <UserPlus class="size-3.5" />
                {{ $t('Add people') }}
            </Button>

            <!-- Pins: opens the pinned-messages popover. The pin glyph fills brass
                 only when the channel has pins (marking pinned-ness); the inline
                 count rides beside it. The button itself stays neutral like the
                 Search and options controls. -->
            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        variant="ghost"
                        size="sm"
                        type="button"
                        data-test="masthead-pins"
                        :aria-label="$t('Pinned messages')"
                        class="gap-1 px-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                        @click="emit('openPins')"
                    >
                        <Pin
                            class="size-4"
                            :class="
                                props.pinCount > 0
                                    ? 'fill-brass text-brass'
                                    : ''
                            "
                        />
                        <span
                            v-if="props.pinCount > 0"
                            data-test="masthead-pins-count"
                            class="text-[12px] font-semibold tabular-nums"
                            >{{ props.pinCount }}</span
                        >
                    </Button>
                </TooltipTrigger>
                <TooltipContent>{{ $t('Pinned messages') }}</TooltipContent>
            </Tooltip>

            <Link
                :href="searchMessages(props.teamSlug).url"
                data-test="masthead-search"
                :aria-label="$t('Search messages')"
                class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
            >
                <Search class="size-4" />
            </Link>

            <DropdownMenu
                v-if="
                    props.canManagePreferences ||
                    props.canArchive ||
                    props.canLeave
                "
            >
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="$t('Channel options')"
                        data-test="channel-options"
                        class="size-auto rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        <EllipsisVertical class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-56">
                    <template v-if="props.canManagePreferences">
                        <!-- Starring files a channel into the sidebar's "Starred"
                             group; DMs live in their own fixed group and are never
                             filed, so the affordance is hidden for them. -->
                        <DropdownMenuItem
                            v-if="!props.channel.isDirect"
                            data-test="star-channel"
                            :aria-pressed="props.starred"
                            @select="
                                (event: Event) => {
                                    event.preventDefault();
                                    emit('toggleStar');
                                }
                            "
                        >
                            <Star
                                :class="
                                    props.starred
                                        ? 'fill-current text-amber-500'
                                        : ''
                                "
                            />
                            {{
                                props.starred
                                    ? $t('Unstar channel')
                                    : $t('Star channel')
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator v-if="!props.channel.isDirect" />
                        <DropdownMenuLabel
                            class="text-[11px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                        >
                            {{ $t('Notifications') }}
                        </DropdownMenuLabel>
                        <DropdownMenuRadioGroup
                            :model-value="props.notificationLevel"
                            @update:model-value="
                                (value) =>
                                    emit('notificationLevelChange', value)
                            "
                        >
                            <DropdownMenuRadioItem
                                v-for="level in props.notificationLevels"
                                :key="level.value"
                                :value="level.value"
                                :data-test="`notification-level-${level.value}`"
                            >
                                {{ level.label }}
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                        <DropdownMenuSeparator />
                        <DropdownMenuCheckboxItem
                            :model-value="props.muted"
                            data-test="mute-channel"
                            @update:model-value="
                                (value) => emit('muteChange', value)
                            "
                            @select="(event: Event) => event.preventDefault()"
                        >
                            {{ $t('Mute channel') }}
                        </DropdownMenuCheckboxItem>
                    </template>
                    <template v-if="props.canArchive">
                        <DropdownMenuSeparator
                            v-if="props.canManagePreferences"
                        />
                        <DropdownMenuItem
                            data-test="archive-channel"
                            class="text-destructive-text focus:text-destructive-text"
                            @select="emit('archive')"
                        >
                            <Archive class="size-4" />
                            {{ $t('Archive channel') }}
                        </DropdownMenuItem>
                    </template>
                    <template v-if="props.canLeave">
                        <DropdownMenuSeparator
                            v-if="
                                props.canManagePreferences || props.canArchive
                            "
                        />
                        <DropdownMenuItem
                            data-test="leave-channel"
                            class="text-destructive-text focus:text-destructive-text"
                            @select="emit('leave')"
                        >
                            <LogOut class="size-4" />
                            {{
                                props.channel.isDirect
                                    ? $t('Leave conversation')
                                    : $t('Leave channel')
                            }}
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>
