import { router, usePage } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted } from 'vue';

/**
 * Surface a brand-new direct message in the sidebar live.
 *
 * An empty DM the recipient was never messaged in is hidden from them (see the
 * sidebar listing predicate), so when someone messages them for the first time
 * the DM must appear without a manual reload. This subscribes to the viewer's
 * own private `user.{id}` channel and, on a `DirectMessageStarted` signal,
 * reloads only the `channels` prop: the DM then passes the predicate and the
 * sidebar fleet auto-subscribes to `channel.{dmId}`. Counts are recomputed
 * server-side, so the unread badge is correct even if the reload lands after
 * the first message itself.
 */
export function useNewDirectMessages(): void {
    const page = usePage();

    const currentUserId = computed(() => String(page.props.auth.user.id));

    function channelName(): string {
        return `user.${currentUserId.value}`;
    }

    // Echo opens a websocket, so touch it only in the browser (never on the SSR
    // pass). The authenticated user is stable for the session, so a single
    // subscribe/teardown pair is enough.
    onMounted(() => {
        echo()
            .private(channelName())
            .listen('DirectMessageStarted', () => {
                router.reload({ only: ['channels'] });
            });
    });

    onBeforeUnmount(() => {
        echo().leave(channelName());
    });
}
