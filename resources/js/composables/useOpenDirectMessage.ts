import { router } from '@inertiajs/vue3';
import { store as openDm } from '@/actions/App/Http/Controllers/Channels/DirectMessageController';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';

/**
 * Open (find-or-create) a direct message with a team member and navigate into
 * it. Shared by every DM entry point — the sidebar people picker, the
 * user hover-card "Message" action, and the ⌘K "People" group — so they all
 * reach the same deduped open-or-create flow.
 */
export function useOpenDirectMessage(teamSlug: () => string) {
    const { t } = useTranslations();
    const toast = useToast();

    function openDirectMessage(userId: string): void {
        router.post(
            openDm(teamSlug()).url,
            { user_id: userId },
            {
                preserveScroll: false,
                onError: () => {
                    toast.error(t('Failed to open the conversation'));
                },
            },
        );
    }

    return { openDirectMessage };
}
