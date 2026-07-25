import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { summarizeUnreadElsewhere } from '@/lib/unreadElsewhere';
import type { UnreadElsewhereSummary } from '@/lib/unreadElsewhere';

/**
 * The unread rollup behind the mobile sidebar toggle's badge.
 *
 * Purely derived: the shared `channels` prop already carries every
 * conversation's counts on each channel route, and {@see useSidebarBadges}
 * already keeps it fresh — an arrival, or a read on another device via
 * `ReadStateAdvanced`, debounces a partial reload of that prop. So the badge
 * tracks both without subscribing to anything of its own.
 *
 * Pages that carry no sidebar (auth, settings) simply have no `channels` prop
 * and roll up to nothing.
 */
export function useUnreadElsewhere(): ComputedRef<UnreadElsewhereSummary> {
    const page = usePage();

    return computed(() =>
        summarizeUnreadElsewhere(
            page.props.channels ?? [],
            (page.props.channel as { id?: string } | undefined)?.id ?? null,
        ),
    );
}
