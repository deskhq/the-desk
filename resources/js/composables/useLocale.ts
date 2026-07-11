import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { fetchCatalog, i18n, setMessages } from '@/lib/i18n';
import { update } from '@/routes/locale';
import type { AppLocale } from '@/types';

/**
 * Read and mutate the current user's locale preference. The value is the shared
 * `auth.user.locale` prop, so every consumer stays in sync.
 */
export function useLocale() {
    const page = usePage();

    const locale = computed<AppLocale>(
        () => (page.props.auth.user?.locale ?? i18n.locale) as AppLocale,
    );

    /**
     * Switch language: swap the catalog first so the UI re-renders immediately
     * without a full reload, then persist the choice. The shared prop refreshes
     * from the redirect, so no optimistic state is needed.
     */
    async function updateLocale(next: AppLocale): Promise<void> {
        const messages = await fetchCatalog(next);
        setMessages(next, messages);

        router.patch(
            update().url,
            { locale: next },
            { preserveScroll: true, preserveState: true },
        );
    }

    return { locale, updateLocale };
}
