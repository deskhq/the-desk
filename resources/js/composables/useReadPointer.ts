import { router } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { read as markChannelRead } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { useDebouncedPost } from '@/composables/useDebouncedPost';
import { backgroundVisit } from '@/lib/backgroundVisit';
import { UNREAD_DIGEST_PROPS } from '@/lib/reloadProps';

export interface ReadPointerOptions {
    teamSlug: () => string;
    channelSlug: () => string;
}

/**
 * Advance the read pointer for the open channel so its sidebar badge clears.
 * Debounced and gated on tab focus: a channel is only "read" while the user is
 * actually looking at it, and a burst of arriving messages collapses to one
 * request. The redirect refreshes just the unread digest — the badge is the
 * only thing this write moves, and reloading the roster to move it was the
 * second PHP round trip every click used to pay.
 *
 * The Echo socket id rides along so the resulting `ReadStateAdvanced` broadcast
 * skips this tab: the response above already brings it fresh counts, and its
 * own signal would only buy a second, redundant sidebar reload. The reader's
 * *other* devices still receive it, which is the point of the broadcast.
 */
export function useReadPointer(options: ReadPointerOptions): {
    markRead: () => void;
} {
    const readPost = useDebouncedPost(
        () => {
            const socketId = echo().socketId();

            router.post(
                markChannelRead({
                    team: options.teamSlug(),
                    channel: options.channelSlug(),
                }).url,
                {},
                {
                    // Interrupting a thread-panel GET strands the panel empty (#581)
                    // and the redirect-follow would drop the `?thread=` param; see
                    // {@see backgroundVisit}.
                    ...backgroundVisit,
                    preserveScroll: true,
                    preserveState: true,
                    only: UNREAD_DIGEST_PROPS,
                    headers: socketId ? { 'X-Socket-ID': socketId } : {},
                },
            );
        },
        { delay: 400, gate: () => document.hasFocus() },
    );

    return { markRead: () => readPost.schedule() };
}
