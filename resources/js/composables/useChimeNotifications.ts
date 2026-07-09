import { usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { playChime, unlockChimeAudio } from '@/lib/chimeSounds';
import { shouldChime } from '@/lib/shouldChime';
import type { ChimeSound, Message } from '@/types';

/**
 * Play a chime for qualifying realtime messages across every channel in the
 * sidebar — not just the open one — so activity elsewhere is still audible.
 *
 * Mounted once in the persistent channel layout, it subscribes to each sidebar
 * channel's broadcast and runs {@see shouldChime} per arrival. Show.vue owns the
 * open channel's subscription and tears it down with a full `leave` on navigation,
 * which also drops our listener on it; the post-flush reconcile below re-attaches
 * the channel it just left. Chimes for the open channel are suppressed while it is
 * focused, and Show.vue never chimes, so there is no double playback.
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

    // Only reconcile when the set of channels or the open one actually changes,
    // not on every badge-count refresh of the shared `channels` prop.
    const subscriptionKey = computed(
        () =>
            `${channels.value.map((channel) => channel.id).join('|')}::${activeChannelId.value ?? ''}`,
    );

    const bound = new Set<string>();
    let previousActiveId: string | null = null;

    function channelName(id: string): string {
        return `channel.${id}`;
    }

    function handleMessage(channelId: string, message: Message): void {
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
        });

        if (decision) {
            playChime(chimeSound.value);
        }
    }

    function listen(id: string): void {
        echo()
            .private(channelName(id))
            .listen('MessageSent', (message: Message) => {
                handleMessage(id, message);
            });
    }

    function reconcile(): void {
        const desired = new Set(channels.value.map((channel) => channel.id));
        const active = activeChannelId.value;

        if (previousActiveId !== null && previousActiveId !== active) {
            bound.delete(previousActiveId);
        }

        previousActiveId = active;

        for (const id of desired) {
            if (!bound.has(id)) {
                listen(id);
                bound.add(id);
            }
        }

        for (const id of [...bound]) {
            if (!desired.has(id)) {
                echo().leave(channelName(id));
                bound.delete(id);
            }
        }
    }

    function leaveAll(): void {
        for (const id of bound) {
            echo().leave(channelName(id));
        }

        bound.clear();
    }

    onMounted(() => {
        // The autoplay policy keeps the AudioContext suspended until a gesture.
        window.addEventListener('pointerdown', unlockChimeAudio, {
            once: true,
        });
        window.addEventListener('keydown', unlockChimeAudio, { once: true });
        reconcile();
    });

    // Post-flush so it runs after Show.vue's own per-channel teardown.
    watch(subscriptionKey, reconcile, { flush: 'post' });

    onBeforeUnmount(() => {
        window.removeEventListener('pointerdown', unlockChimeAudio);
        window.removeEventListener('keydown', unlockChimeAudio);
        leaveAll();
    });
}
