import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { useChannelFleetSubscription } from '@/composables/useChannelFleetSubscription';
import { playChime, unlockChimeAudio } from '@/lib/chimeSounds';
import { isDndActiveNow } from '@/lib/dnd';
import { shouldChime } from '@/lib/shouldChime';
import type { ChimeSound } from '@/types';

/**
 * Play a chime for qualifying realtime messages across every channel in the
 * sidebar — not just the open one — so activity elsewhere is still audible.
 *
 * Mounted once in the persistent channel layout, it rides
 * {@see useChannelFleetSubscription} — the shared subscribe/reconcile/teardown
 * engine — and runs {@see shouldChime} per arrival. Chimes for the open channel
 * are suppressed while it is focused, and the open channel page never chimes, so
 * there is no double playback.
 */
export function useChimeNotifications(): void {
    const page = usePage();

    const currentUserId = computed(() => String(page.props.auth.user.id));
    const chimeSound = computed<ChimeSound>(
        () => page.props.auth.user.chime_sound ?? 'ping',
    );
    const channels = computed(() => page.props.channels ?? []);
    const activeChannelId = computed(
        () => (page.props.channel as { id?: string } | undefined)?.id ?? null,
    );

    useChannelFleetSubscription((channelId, message) => {
        const preference =
            channels.value.find((channel) => channel.id === channelId) ?? null;

        const decision = shouldChime({
            chimeEnabled: chimeSound.value !== 'off',
            isOwnMessage: message.user.id === currentUserId.value,
            isChannelMessage:
                message.threadRootId === null || message.sentToChannel,
            mentionsCurrentUser: message.mentions.some(
                (mention) => mention.id === currentUserId.value,
            ),
            channel: preference
                ? {
                      muted: preference.muted,
                      notificationLevel: preference.notificationLevel,
                  }
                : null,
            tabHasFocus: typeof document !== 'undefined' && document.hasFocus(),
            isActiveChannel: channelId === activeChannelId.value,
            // Evaluated at arrival time, not page-load time, so a pause lapsing
            // or a quiet-hours window opening takes effect without a visit.
            dndActive: isDndActiveNow(
                page.props.auth.user.dnd ?? null,
                page.props.auth.user.timezone ?? null,
            ),
        });

        if (decision) {
            playChime(chimeSound.value);
        }
    });

    onMounted(() => {
        // The autoplay policy keeps the AudioContext suspended until a gesture.
        window.addEventListener('pointerdown', unlockChimeAudio, {
            once: true,
        });
        window.addEventListener('keydown', unlockChimeAudio, { once: true });
    });

    onBeforeUnmount(() => {
        window.removeEventListener('pointerdown', unlockChimeAudio);
        window.removeEventListener('keydown', unlockChimeAudio);
    });
}
