import type { AcceptableValue } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { update as updateChannelPreferences } from '@/actions/App/Http/Controllers/Channels/ChannelPreferenceController';
import { update as updateChannelStar } from '@/actions/App/Http/Controllers/Channels/ChannelStarController';
import {
    snapshotRef,
    useOptimisticWrite,
} from '@/composables/useOptimisticWrite';
import { useTranslations } from '@/composables/useTranslations';
import type { AlertPreference } from '@/lib/alerts';
import { notificationIndicator } from '@/lib/notificationIndicator';
import type { NotificationIndicator } from '@/lib/notificationIndicator';
import { CHANNEL_LIST_PROPS } from '@/lib/reloadProps';
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
    /** The live mute + level pair the alert rule reads, for the realtime router. */
    alertPreference: ComputedRef<AlertPreference>;
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
    const { t } = useTranslations();
    const { write } = useOptimisticWrite();
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

    // The optimistic pair the alert rule reads, handed on rather than resolved
    // here: whether an arriving reply alerts also depends on whether it mentions
    // the viewer, which only the realtime router knows. Reading it live is what
    // makes a mute taken this second apply to the next reply.
    const alertPreference = computed<AlertPreference>(() => ({
        muted: muted.value,
        notificationLevel: notificationLevel.value,
    }));

    // A compact header cue for the member's non-default notification state,
    // shared with the sidebar rows; the "all" default shows nothing to keep the
    // header clean.
    const notificationStatus = computed(() =>
        notificationIndicator(muted.value, notificationLevel.value),
    );

    function toggleStar(): void {
        write({
            capture: () => snapshotRef(starred),
            apply: () => {
                starred.value = !starred.value;
            },
            // These refs are shared across channels and reseeded on every
            // switch, so a rollback is only ever right for the channel the write
            // was fired from.
            subject: options.channelId,
            method: 'patch',
            url: updateChannelStar({
                team: options.teamSlug(),
                channel: options.channelSlug(),
            }).url,
            // Read after `apply`, because the endpoint takes the new value and
            // the write is what decided it.
            data: () => ({ starred: starred.value }),
            only: CHANNEL_LIST_PROPS,
            // Applied optimistically, so nothing on screen waits for it: it must
            // not interrupt the navigation a user often fires right after
            // starring; see {@see backgroundVisit}.
            background: true,
            failure: t('Failed to update the channel'),
        });
    }

    /**
     * Save the notification pair. Both preferences post together — the endpoint
     * takes them as one payload — so a change to either snapshots only the ref
     * it moves and the other rides along at whatever it already was.
     */
    function savePreferences(changed: Ref<unknown>, apply: () => void): void {
        write({
            capture: () => snapshotRef(changed),
            apply,
            subject: options.channelId,
            method: 'patch',
            url: updateChannelPreferences({
                team: options.teamSlug(),
                channel: options.channelSlug(),
            }).url,
            data: () => ({
                muted: muted.value,
                notification_level: notificationLevel.value,
            }),
            only: CHANNEL_LIST_PROPS,
            // Optimistic like the star above, and dismissing the menu often
            // coincides with a navigation; see {@see backgroundVisit}.
            background: true,
            failure: t('Failed to update notification preferences'),
        });
    }

    function onNotificationLevelChange(value: AcceptableValue): void {
        savePreferences(notificationLevel, () => {
            notificationLevel.value = value as NotificationLevel;
        });
    }

    function onMuteChange(value: boolean): void {
        savePreferences(muted, () => {
            muted.value = value;
        });
    }

    return {
        notificationLevel,
        muted,
        starred,
        alertPreference,
        notificationStatus,
        toggleStar,
        onNotificationLevelChange,
        onMuteChange,
    };
}
