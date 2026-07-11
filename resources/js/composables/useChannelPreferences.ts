import { router } from '@inertiajs/vue3';
import type { AcceptableValue } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { toast } from 'vue-sonner';
import { update as updateChannelPreferences } from '@/actions/App/Http/Controllers/Channels/ChannelPreferenceController';
import { update as updateChannelStar } from '@/actions/App/Http/Controllers/Channels/ChannelStarController';
import { notificationIndicator } from '@/lib/notificationIndicator';
import type { NotificationIndicator } from '@/lib/notificationIndicator';
import type { Channel, NotificationLevel } from '@/types';

/** The member's own star/mute/notification state for the open channel. */
type ChannelPreferenceState = Pick<
    Channel,
    'notificationLevel' | 'muted' | 'starred'
>;

export interface ChannelPreferencesOptions {
    /** The open channel's id; a change reseeds the preferences from the server. */
    channelId: () => string;
    /** The channel's server-seeded preference state, reread on channel switch. */
    channel: () => ChannelPreferenceState;
    /** The current team's slug, for the preference/star routes. */
    teamSlug: () => string;
    /** The open channel's slug, for the preference/star routes. */
    channelSlug: () => string;
}

export interface ChannelPreferences {
    /** The member's notification level; drives the header menu radio group. */
    notificationLevel: Ref<NotificationLevel>;
    /** Whether the member has muted the channel. */
    muted: Ref<boolean>;
    /** Whether the member has starred the channel. */
    starred: Ref<boolean>;
    /** Whether thread-unread dots are silenced (muted or a quieted level). */
    threadUnreadSuppressed: ComputedRef<boolean>;
    /** A compact header cue for a non-default notification state, or null. */
    notificationStatus: ComputedRef<NotificationIndicator | null>;
    /** Star or unstar the channel, optimistically, rolled back on error. */
    toggleStar: () => void;
    /** Change the notification level, optimistically, rolled back on error. */
    onNotificationLevelChange: (value: AcceptableValue) => void;
    /** Toggle mute, optimistically, rolled back on error. */
    onMuteChange: (value: boolean) => void;
}

/**
 * Own the member's per-channel notification preferences: star, mute, and
 * notification level, each seeded from the server and reseeded on every channel
 * switch. Changes save optimistically — the sidebar reloads to reflect the new
 * badge/dimming state — and roll back if the request fails.
 */
export function useChannelPreferences(
    options: ChannelPreferencesOptions,
): ChannelPreferences {
    const notificationLevel = ref<NotificationLevel>(
        options.channel().notificationLevel,
    );
    const muted = ref<boolean>(options.channel().muted);
    const starred = ref<boolean>(options.channel().starred);

    // Reseed from the server on channel switch; each preference is the outgoing
    // channel's until the new channel's state is adopted here.
    watch(options.channelId, () => {
        const channel = options.channel();
        notificationLevel.value = channel.notificationLevel;
        muted.value = channel.muted;
        starred.value = channel.starred;
    });

    // Thread-unread dots are silenced under the same rule as the sidebar's unread
    // badge: a muted channel or any level below "all". Mirrors the server's
    // suppression so a live dot and a navigation-time dot agree.
    const threadUnreadSuppressed = computed(
        () => muted.value || notificationLevel.value !== 'all',
    );

    // A compact header cue for the member's non-default notification state,
    // shared with the sidebar rows; the "all" default shows nothing to keep the
    // header clean.
    const notificationStatus = computed(() =>
        notificationIndicator(muted.value, notificationLevel.value),
    );

    function toggleStar(): void {
        const previous = starred.value;
        starred.value = !previous;

        router.patch(
            updateChannelStar({
                team: options.teamSlug(),
                channel: options.channelSlug(),
            }).url,
            { starred: starred.value },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['channels'],
                onError: () => {
                    starred.value = previous;
                    toast.error(
                        'Failed to update the channel. Please try again.',
                    );
                },
            },
        );
    }

    function savePreferences(rollback: () => void): void {
        router.patch(
            updateChannelPreferences({
                team: options.teamSlug(),
                channel: options.channelSlug(),
            }).url,
            { muted: muted.value, notification_level: notificationLevel.value },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['channels'],
                onError: () => {
                    rollback();
                    toast.error(
                        'Failed to update notification preferences. Please try again.',
                    );
                },
            },
        );
    }

    function onNotificationLevelChange(value: AcceptableValue): void {
        const previous = notificationLevel.value;
        notificationLevel.value = value as NotificationLevel;
        savePreferences(() => {
            notificationLevel.value = previous;
        });
    }

    function onMuteChange(value: boolean): void {
        const previous = muted.value;
        muted.value = value;
        savePreferences(() => {
            muted.value = previous;
        });
    }

    return {
        notificationLevel,
        muted,
        starred,
        threadUnreadSuppressed,
        notificationStatus,
        toggleStar,
        onNotificationLevelChange,
        onMuteChange,
    };
}
