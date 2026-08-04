import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { EMPTY_DIGEST } from '@/lib/unreadDigest';
import type { UnreadDigest } from '@/types/unread';

/**
 * The shared unread digest, as every badge in the shell reads it.
 *
 * It rides every visit rather than being cached, because it is the one shared
 * prop with no honest invalidation trigger — it *is* the thing that changes. So
 * a surface that wants a badge reads it here rather than off the roster it is
 * rendering: the roster is allowed to be stale, this is not.
 *
 * Defaults to nothing waiting so a surface outside a workspace — an auth page,
 * a settings page reached before the shell has any workspace to describe —
 * renders no badges rather than failing on a missing prop.
 */
export function useUnreadDigest(): ComputedRef<UnreadDigest> {
    const page = usePage();

    return computed(() => page.props.unread ?? EMPTY_DIGEST);
}
