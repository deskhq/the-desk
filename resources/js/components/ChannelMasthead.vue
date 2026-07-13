<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Archive,
    Check,
    EllipsisVertical,
    LogOut,
    Pin,
    Search,
    Star,
} from '@lucide/vue';
import type { AcceptableValue } from 'reka-ui';
import { computed } from 'vue';
import { index as searchMessages } from '@/actions/App/Http/Controllers/Channels/SearchController';
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
import type {
    Channel,
    Mention,
    NotificationLevel,
    NotificationLevelOption,
} from '@/types';

const props = defineProps<{
    channel: Channel;
    teamSlug: string;
    // The team roster the page already carries for the composer, reused for the
    // overlapping member facepile.
    members: Mention[];
    // Ids of team members currently online, driving the DM presence dot.
    onlineIds: Set<string>;
    // The viewer-relative title (self-DM reads "You"); the page also feeds it to
    // `<Head>`, so it is resolved once there and passed down.
    title: string;
    canManagePreferences: boolean;
    canArchive: boolean;
    // Whether the viewer may leave the channel — a member of a standard channel
    // that isn't #general. Drives the "Leave channel" menu item.
    canLeave: boolean;
    notificationLevels: NotificationLevelOption[];
    starred: boolean;
    muted: boolean;
    // The channel's pinned-message count, driving the pins button's badge. Kept
    // live from the `MessagePinned` broadcast by the page.
    pinCount: number;
    notificationLevel: NotificationLevel;
    // A compact header cue for a non-default notification state, or null.
    notificationStatus: NotificationIndicator | null;
    // The realtime connection cue: a reconnecting pill, a transient back-online
    // confirmation, or null when the socket is steadily connected.
    connectionPill?: ConnectionPill;
}>();

const emit = defineEmits<{
    toggleStar: [];
    notificationLevelChange: [value: AcceptableValue];
    muteChange: [value: boolean];
    archive: [];
    leave: [];
    openPins: [];
}>();

// How many member avatars the masthead shows before collapsing the rest into a
// single "+N" overflow chip.
const MAX_MASTHEAD_AVATARS = 3;

// The overlapping member avatars for the masthead's right side.
const mastheadAvatars = computed(() =>
    memberAvatarStack(props.members, MAX_MASTHEAD_AVATARS),
);

// A DM renders viewer-relative: the other participant's presence dot follows the
// team roster. A DM is a fixed pair, so the "who's in the channel" facepile is
// meaningless and hidden.
const dmParticipantOnline = computed(
    () =>
        props.channel.dmUserId != null &&
        props.onlineIds.has(props.channel.dmUserId),
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
                <!-- A DM shows the participant's avatar + presence dot instead of
                     the channel "#"; the name is already viewer-relative (self
                     reads "You"). -->
                <span
                    v-if="props.channel.isDirect"
                    data-test="masthead-dm-avatar"
                    class="relative inline-flex size-7 shrink-0"
                >
                    <span
                        class="flex size-7 items-center justify-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary select-none"
                        aria-hidden="true"
                        >{{ getInitials(props.channel.name) }}</span
                    >
                    <span
                        :data-online="dmParticipantOnline"
                        :aria-label="
                            dmParticipantOnline ? $t('Online') : $t('Offline')
                        "
                        class="absolute -right-0.5 -bottom-0.5 size-2.5 rounded-full ring-2 ring-card"
                        :class="
                            dmParticipantOnline
                                ? 'bg-emerald-500'
                                : 'bg-muted-foreground/50'
                        "
                    />
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
                class="flex -space-x-1.5"
            >
                <span class="sr-only">
                    {{
                        props.members.length === 1
                            ? $t(':count member', {
                                  count: props.members.length,
                              })
                            : $t(':count members', {
                                  count: props.members.length,
                              })
                    }}
                </span>
                <Avatar
                    v-for="member in mastheadAvatars.visible"
                    :key="member.id"
                    class="size-6 text-[9px] ring-2 ring-card"
                    :title="member.name"
                    aria-hidden="true"
                >
                    <AvatarImage
                        v-if="member.avatar"
                        :src="member.avatar"
                        :alt="member.name"
                    />
                    <AvatarFallback
                        class="bg-primary/10 font-semibold text-primary"
                    >
                        {{ getInitials(member.name) }}
                    </AvatarFallback>
                </Avatar>
                <span
                    v-if="mastheadAvatars.overflow > 0"
                    class="flex size-6 items-center justify-center rounded-full bg-muted text-[9px] font-semibold text-muted-foreground ring-2 ring-card select-none"
                    aria-hidden="true"
                >
                    +{{ mastheadAvatars.overflow }}
                </span>
            </span>

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
                            class="text-destructive focus:text-destructive"
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
                            class="text-destructive focus:text-destructive"
                            @select="emit('leave')"
                        >
                            <LogOut class="size-4" />
                            {{ $t('Leave channel') }}
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>
