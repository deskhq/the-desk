<script setup lang="ts">
import ChannelMasthead from '@/components/ChannelMasthead.vue';
import PinsPanel from '@/components/PinsPanel.vue';
import { useChannelPins } from '@/composables/useChannelPins';
import { useChannelPreferences } from '@/composables/useChannelPreferences';
import type { ConnectionPill } from '@/composables/useConnectionState';
import type { RenderedPresence } from '@/lib/presence';
import type {
    Channel,
    Message,
    NotificationLevelOption,
    RosterMember,
} from '@/types';

/**
 * The channel's header: the masthead and the pins popover it opens.
 *
 * It owns the two pieces of state that belong to the header rather than to the
 * conversation — the member's own star/mute/notification-level preferences, and
 * the pin count with its panel — and exposes the parts the page's realtime
 * router has to reconcile against.
 */
const props = defineProps<{
    team: { slug: string };
    channel: Channel;
    members: RosterMember[];
    presenceFor: (userId: string) => RenderedPresence;
    isDndFor: (userId: string) => boolean;
    /** What the channel is called from this viewer's side of it. */
    title: string;
    canManagePreferences: boolean;
    canArchive: boolean;
    /** Whether the viewer may delete the channel (team Admin+, not #general). */
    canDelete: boolean;
    canLeave: boolean;
    canAddPeople: boolean;
    /** Whether the viewer may pin and unpin, as the panel's rows offer. */
    canPin: boolean;
    notificationLevels: NotificationLevelOption[];
    /** The channel's pinned messages, most-recently-pinned first. */
    pins: Message[];
    /** The server's pin count, resynced on partial reloads and switches. */
    pinCount: number;
    connectionPill: ConnectionPill;
    /** Whether conversation has scrolled under the masthead, taking a shadow. */
    scrolled: boolean;
    viewerTimeZone: string | null;
}>();

const emit = defineEmits<{
    /** The channel-details modal was asked for from the masthead menu. */
    openDetails: [];
    archive: [];
    delete: [];
    leave: [];
    addPeople: [];
    /** A pinned message was picked: bring it into view in the timeline. */
    jump: [messageId: string];
    unpin: [message: Message];
}>();

/**
 * The member's own star/mute/notification-level preferences for this channel:
 * seeded from the server, reseeded on every channel switch, saved optimistically
 * and rolled back on error. `alertPreference` is the pair the alert rule reads,
 * fed to the page's realtime router.
 */
const {
    notificationLevel,
    muted,
    starred,
    alertPreference,
    notificationStatus,
    toggleStar,
    onNotificationLevelChange,
    onMuteChange,
} = useChannelPreferences({
    channelId: () => props.channel.id,
    channel: () => props.channel,
    teamSlug: () => props.team.slug,
    channelSlug: () => props.channel.slug,
});

const { pinCount, pinsPanelOpen, openPinsPanel, jumpToPin } = useChannelPins({
    pinCount: () => props.pinCount,
    jumpToMessage: (id) => emit('jump', id),
});

defineExpose({
    /** The mute + level pair the alert rule reads for this channel. */
    alertPreference,
    /** Patch the badge from the MessagePinned broadcast. */
    setPinCount: (count: number): void => {
        pinCount.value = count;
    },
    /** Close the pins popover, e.g. on a channel switch. */
    closePins: (): void => {
        pinsPanelOpen.value = false;
    },
});
</script>

<template>
    <div class="contents">
        <ChannelMasthead
            :channel="props.channel"
            :members="props.members"
            :presence-for="props.presenceFor"
            :is-dnd-for="props.isDndFor"
            :title="props.title"
            :can-manage-preferences="props.canManagePreferences"
            :can-archive="props.canArchive"
            :can-delete="props.canDelete"
            :can-leave="props.canLeave"
            :can-add-people="props.canAddPeople"
            :notification-levels="props.notificationLevels"
            :starred="starred"
            :muted="muted"
            :pin-count="pinCount"
            :notification-level="notificationLevel"
            :notification-status="notificationStatus"
            :connection-pill="props.connectionPill"
            :scrolled="props.scrolled"
            @open-details="emit('openDetails')"
            @toggle-star="toggleStar"
            @notification-level-change="onNotificationLevelChange"
            @mute-change="onMuteChange"
            @archive="emit('archive')"
            @delete="emit('delete')"
            @leave="emit('leave')"
            @add-people="emit('addPeople')"
            @open-pins="openPinsPanel"
        />

        <PinsPanel
            v-if="pinsPanelOpen"
            :pins="props.pins"
            :pin-count="pinCount"
            :can-pin="props.canPin"
            :viewer-time-zone="props.viewerTimeZone"
            @close="pinsPanelOpen = false"
            @jump="jumpToPin"
            @unpin="(message) => emit('unpin', message)"
        />
    </div>
</template>
