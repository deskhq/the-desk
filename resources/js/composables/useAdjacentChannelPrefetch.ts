import { usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { show } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { adjacentChannels, prefetchChannel } from '@/lib/prefetch';

/**
 * Fetch the two channels a ⌘↑ / ⌘↓ walk can reach from the open one.
 *
 * Prediction rather than intent, because the walk has no hover to trigger on:
 * the sidebar rows buy their head start from a pointer travelling towards them,
 * and a keyboard jump offers nothing to read. For any active channel, though,
 * its neighbours are exactly two computable URLs — so the head start can be
 * taken speculatively instead.
 *
 * The cost is therefore fixed at two speculative requests per navigation and
 * does not grow with the workspace, which is the property that ruled `mount`
 * out for the sidebar, where it would have been one request per row. It is
 * still two full framework boots, which is why it is bounded at two.
 *
 * Runs only while a channel is open. Settings and browse pages mount this same
 * shell, and there is no walk in progress on them.
 */
export function useAdjacentChannelPrefetch(): void {
    const page = usePage();

    const activeSlug = computed(
        () =>
            (page.props.channel as { slug?: string } | undefined)?.slug ?? null,
    );

    watch(
        [activeSlug, () => page.props.channels, () => page.props.currentTeam],
        ([slug, channels, team]) => {
            if (slug === null || !team) {
                return;
            }

            for (const channel of adjacentChannels(channels ?? [], slug)) {
                prefetchChannel(
                    show({ team: team.slug, channel: channel.slug }).url,
                    channel.id,
                );
            }
        },
        { immediate: true },
    );
}
