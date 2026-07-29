<script setup lang="ts">
import { Pin, Search, UserPlus } from '@lucide/vue';
import type { AcceptableValue } from 'reka-ui';
import MastheadConnectionPill from '@/components/channel/MastheadConnectionPill.vue';
import MastheadFacepile from '@/components/channel/MastheadFacepile.vue';
import MastheadOptionsMenu from '@/components/channel/MastheadOptionsMenu.vue';
import MastheadTitle from '@/components/channel/MastheadTitle.vue';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { ConnectionPill } from '@/composables/useConnectionState';
import { useMastheadSearch } from '@/composables/useMastheadSearch';
import type { NotificationIndicator } from '@/lib/notificationIndicator';
import type { RenderedPresence } from '@/lib/presence';
import type {
    Channel,
    Mention,
    NotificationLevel,
    NotificationLevelOption,
} from '@/types';

const props = defineProps<{
    channel: Channel;
    /**
     * The team roster the page already carries for the composer, reused for the
     * overlapping member facepile.
     */
    members: Mention[];
    /** How each team member reads on the presence roster, driving every dot here. */
    presenceFor: (userId: string) => RenderedPresence;
    /** Whether each member is in do-not-disturb, driving the crescent badge. */
    isDndFor?: (userId: string) => boolean;
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
    /**
     * Whether the conversation beneath has been scrolled away from its top.
     * Drives the masthead's hairline shadow, which marks it as a layer over the
     * timeline rather than part of it.
     */
    scrolled?: boolean;
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

const { isMobile, openSearch } = useMastheadSearch();
</script>

<template>
    <!-- Below the breakpoint the masthead is a layer over the conversation: it
         stays pinned while the timeline scrolls beneath it, and translucency
         plus a blur keep the messages legible through it. The hairline shadow
         only appears once there is something scrolled underneath. -->
    <!-- Its own container: what the masthead can fit is a question about the
         width of the workspace pane, not the window. They part company on a
         tablet, where the dock takes 300px of a 768px screen and leaves the
         masthead the room of a large phone. -->
    <header
        class="@container relative z-20 flex shrink-0 items-center gap-2.5 border-b border-border bg-card/80 px-4 pt-4 pb-3 backdrop-blur-md transition-shadow @2xl:items-end @2xl:gap-4 @2xl:bg-transparent @2xl:px-7 @2xl:pt-5 @2xl:pb-3.5 @2xl:backdrop-blur-none"
        :class="
            props.scrolled
                ? 'shadow-[0_2px_10px_rgba(60,55,40,0.07)] @2xl:shadow-none'
                : ''
        "
    >
        <SidebarTrigger
            class="-my-1.5 -ml-2 size-9 shrink-0 text-muted-foreground md:hidden"
        />

        <MastheadTitle
            :channel="props.channel"
            :members="props.members"
            :presence-for="props.presenceFor"
            :is-dnd-for="props.isDndFor"
            :title="props.title"
            :notification-status="props.notificationStatus"
        />

        <div class="flex shrink-0 items-center gap-1 @2xl:gap-3 @2xl:pb-1">
            <MastheadConnectionPill :pill="props.connectionPill" />

            <!-- A DM is a fixed set, so the "who's in the channel" facepile is
                 meaningless there and hidden. -->
            <MastheadFacepile
                v-if="!props.channel.isDirect"
                :members="props.members"
                :presence-for="props.presenceFor"
                :is-dnd-for="props.isDndFor"
            />

            <!-- Add people: opens the picker that grows this DM into (or reuses)
                 a group conversation. Only shown to a member of a DM. Below the
                 breakpoint the pill folds to an icon-only control like the
                 neighbouring pins and search buttons, so the conversation name
                 keeps the row's horizontal space (#801). -->
            <Button
                v-if="props.canAddPeople"
                :variant="isMobile ? 'ghost' : 'outline'"
                :size="isMobile ? 'icon' : 'sm'"
                type="button"
                data-test="masthead-add-people"
                :aria-label="$t('Add people')"
                :class="
                    isMobile
                        ? 'size-9 rounded text-muted-foreground hover:bg-muted hover:text-foreground'
                        : 'h-8 gap-1.5 rounded-full px-4 text-[12.5px] font-semibold'
                "
                @click="emit('addPeople')"
            >
                <UserPlus :class="isMobile ? 'size-4' : 'size-3.5'" />
                <template v-if="!isMobile">{{ $t('Add people') }}</template>
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
                        class="h-9 gap-1 px-1.5 text-muted-foreground hover:bg-muted hover:text-foreground @2xl:h-8"
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

            <Button
                variant="ghost"
                size="icon"
                type="button"
                data-test="masthead-search"
                :aria-label="$t('Search messages')"
                class="size-9 rounded text-muted-foreground hover:bg-muted hover:text-foreground @2xl:size-auto @2xl:p-1"
                @click="openSearch"
            >
                <Search class="size-4" />
            </Button>

            <MastheadOptionsMenu
                :is-direct="props.channel.isDirect"
                :can-manage-preferences="props.canManagePreferences"
                :can-archive="props.canArchive"
                :can-leave="props.canLeave"
                :notification-levels="props.notificationLevels"
                :starred="props.starred"
                :muted="props.muted"
                :notification-level="props.notificationLevel"
                @toggle-star="emit('toggleStar')"
                @notification-level-change="
                    (value) => emit('notificationLevelChange', value)
                "
                @mute-change="(value) => emit('muteChange', value)"
                @archive="emit('archive')"
                @leave="emit('leave')"
            />
        </div>
    </header>
</template>
