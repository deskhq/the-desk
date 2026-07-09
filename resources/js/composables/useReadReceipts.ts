import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { update } from '@/routes/read-receipts';

/**
 * Read and mutate the current user's "share read receipts" preference. The value
 * is the shared `auth.user.share_read_receipts` prop, so every consumer stays in
 * sync; the shared prop refreshes from the redirect, so no optimistic state is
 * needed. When off, the user neither broadcasts nor exposes their read position.
 */
export function useReadReceipts() {
    const page = usePage();

    const shareReadReceipts = computed<boolean>(
        () => page.props.auth.user.share_read_receipts ?? true,
    );

    /**
     * Persist a new sharing choice.
     */
    function updateShareReadReceipts(share: boolean): void {
        router.patch(
            update().url,
            { share_read_receipts: share },
            { preserveScroll: true, preserveState: true },
        );
    }

    return { shareReadReceipts, updateShareReadReceipts };
}
