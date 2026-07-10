import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, ref } from 'vue';
import type { Ref } from 'vue';

const { patch, toastError } = vi.hoisted(() => ({
    patch: vi.fn(),
    toastError: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({ router: { patch } }));
vi.mock('vue-sonner', () => ({ toast: { error: toastError } }));

import { useChannelPreferences } from '@/composables/useChannelPreferences';
import type { ChannelPreferences } from '@/composables/useChannelPreferences';
import type { Channel, NotificationLevel } from '@/types';

type PrefState = Pick<Channel, 'notificationLevel' | 'muted' | 'starred'>;

function channelState(overrides: Partial<PrefState> = {}): PrefState {
    return {
        notificationLevel: 'all',
        muted: false,
        starred: false,
        ...overrides,
    };
}

function withScope(channel: Ref<PrefState>, channelId: Ref<string>) {
    const scope = effectScope();
    let prefs!: ChannelPreferences;

    scope.run(() => {
        prefs = useChannelPreferences({
            channelId: () => channelId.value,
            channel: () => channel.value,
            teamSlug: () => 'acme',
            channelSlug: () => 'general',
        });
    });

    return { prefs, unmount: () => scope.stop() };
}

/** Invoke the onError callback of the nth recorded patch, standing in for a failed request. */
function failPatch(call = 0): void {
    (patch.mock.calls[call][2] as { onError: () => void }).onError();
}

describe('useChannelPreferences', () => {
    beforeEach(() => {
        patch.mockClear();
        toastError.mockClear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('seeds each preference from the server state', () => {
        const { prefs } = withScope(
            ref(
                channelState({
                    notificationLevel: 'mentions',
                    muted: true,
                    starred: true,
                }),
            ),
            ref('id-1'),
        );

        expect(prefs.notificationLevel.value).toBe('mentions');
        expect(prefs.muted.value).toBe(true);
        expect(prefs.starred.value).toBe(true);
    });

    it('reseeds preferences from the server on a channel switch', () => {
        const channel = ref(channelState({ starred: false }));
        const id = ref('id-1');
        const { prefs } = withScope(channel, id);

        channel.value = channelState({
            notificationLevel: 'nothing',
            muted: true,
            starred: true,
        });
        id.value = 'id-2';

        return Promise.resolve().then(() => {
            expect(prefs.notificationLevel.value).toBe('nothing');
            expect(prefs.muted.value).toBe(true);
            expect(prefs.starred.value).toBe(true);
        });
    });

    it('stars optimistically and rolls back on error', () => {
        const { prefs } = withScope(ref(channelState()), ref('id-1'));

        prefs.toggleStar();
        expect(prefs.starred.value).toBe(true);
        expect(patch.mock.calls[0][1]).toEqual({ starred: true });

        failPatch();
        expect(prefs.starred.value).toBe(false);
        expect(toastError).toHaveBeenCalledOnce();
    });

    it('changes the notification level optimistically and rolls back on error', () => {
        const { prefs } = withScope(ref(channelState()), ref('id-1'));

        prefs.onNotificationLevelChange('mentions' as NotificationLevel);
        expect(prefs.notificationLevel.value).toBe('mentions');
        expect(patch.mock.calls[0][1]).toEqual({
            muted: false,
            notification_level: 'mentions',
        });

        failPatch();
        expect(prefs.notificationLevel.value).toBe('all');
        expect(toastError).toHaveBeenCalledOnce();
    });

    it('toggles mute optimistically and rolls back on error', () => {
        const { prefs } = withScope(ref(channelState()), ref('id-1'));

        prefs.onMuteChange(true);
        expect(prefs.muted.value).toBe(true);

        failPatch();
        expect(prefs.muted.value).toBe(false);
        expect(toastError).toHaveBeenCalledOnce();
    });

    it('suppresses thread-unread dots when muted or below "all"', () => {
        const channel = ref(channelState());
        const { prefs } = withScope(channel, ref('id-1'));

        expect(prefs.threadUnreadSuppressed.value).toBe(false);

        prefs.onMuteChange(true);
        expect(prefs.threadUnreadSuppressed.value).toBe(true);

        prefs.onMuteChange(false);
        prefs.onNotificationLevelChange('mentions' as NotificationLevel);
        expect(prefs.threadUnreadSuppressed.value).toBe(true);
    });

    it('derives a header cue only for a non-default notification state', () => {
        const { prefs } = withScope(ref(channelState()), ref('id-1'));

        expect(prefs.notificationStatus.value).toBeNull();

        prefs.onMuteChange(true);
        expect(prefs.notificationStatus.value?.label).toBe('Muted');

        prefs.onMuteChange(false);
        prefs.onNotificationLevelChange('nothing' as NotificationLevel);
        expect(prefs.notificationStatus.value?.label).toBe('Notifications off');

        prefs.onNotificationLevelChange('mentions' as NotificationLevel);
        expect(prefs.notificationStatus.value?.label).toBe('Mentions only');
    });
});
