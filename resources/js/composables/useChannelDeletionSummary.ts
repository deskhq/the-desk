import type { Ref } from 'vue';
import { ref } from 'vue';
import { deletionSummary } from '@/routes/channels';

type Summary = App.Data.ChannelContentSummaryData;

/**
 * Load what deleting a channel would destroy, on demand.
 *
 * The counts are three aggregates nobody but an admin opening the delete dialog
 * reads, so they are fetched when the dialog opens rather than shipped with the
 * channel view. A failed or still-pending fetch leaves `summary` null: the dialog
 * warns in general terms rather than blocking the admin behind a count.
 */
export function useChannelDeletionSummary(): {
    summary: Ref<Summary | null>;
    loading: Ref<boolean>;
    load: (teamSlug: string, channelSlug: string) => Promise<void>;
    reset: () => void;
} {
    const summary = ref<Summary | null>(null);
    const loading = ref(false);

    async function load(teamSlug: string, channelSlug: string): Promise<void> {
        loading.value = true;

        try {
            const response = await fetch(
                deletionSummary({ team: teamSlug, channel: channelSlug }).url,
                { headers: { Accept: 'application/json' } },
            );

            summary.value = response.ok
                ? ((await response.json()) as Summary)
                : null;
        } catch {
            summary.value = null;
        } finally {
            loading.value = false;
        }
    }

    function reset(): void {
        summary.value = null;
        loading.value = false;
    }

    return { summary, loading, load, reset };
}
