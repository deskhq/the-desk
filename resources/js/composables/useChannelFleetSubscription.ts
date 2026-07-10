import { usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { createChannelFleet } from '@/lib/channelFleet';
import type { Message } from '@/types';

/** The Echo private-channel name a channel id broadcasts on. */
function channelName(id: string): string {
    return `channel.${id}`;
}

/**
 * Subscribe to a *set* of channels — every channel in the sidebar — and run a
 * caller-supplied callback for each realtime `MessageSent`. This is the one
 * subscribe/reconcile/teardown lifecycle shared by the sidebar badges and the
 * chimes (and mirrored by the open channel's own single subscription): the pure
 * {@see createChannelFleet} owns the bound-set diffing and active-channel
 * handoff, while this composable wires it to Echo, the shared `channels` prop,
 * and the component lifecycle. Consumers stay thin — they only decide *what to
 * do* on a message.
 *
 * The open channel's subscription is owned by its page, which leaves it on
 * navigation; the post-flush reconcile below runs after that teardown so it
 * re-attaches the channel just left.
 */
export function useChannelFleetSubscription(
    onMessage: (channelId: string, message: Message) => void,
): void {
    const page = usePage();

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

    const fleet = createChannelFleet({
        subscribe(id) {
            echo()
                .private(channelName(id))
                .listen('MessageSent', (message: Message) => {
                    onMessage(id, message);
                });
        },
        leave(id) {
            echo().leave(channelName(id));
        },
    });

    function reconcile(): void {
        fleet.reconcile(
            channels.value.map((channel) => channel.id),
            activeChannelId.value,
        );
    }

    onMounted(reconcile);

    // Post-flush so it runs after the open channel page's own per-channel
    // teardown of the channel it just navigated away from.
    watch(subscriptionKey, reconcile, { flush: 'post' });

    onBeforeUnmount(() => {
        fleet.leaveAll();
    });
}
