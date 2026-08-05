import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { backgroundVisit } from '@/lib/backgroundVisit';
import { fetchCatalog, i18n, setMessages } from '@/lib/i18n';
import { LOCALE_PROPS } from '@/lib/reloadProps';
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
     *
     * The redirect alone does not refresh the props the *server* renders in the
     * reader's language, because those are cached per locale and a language the
     * reader has already used this session is one the client believes it holds.
     * {@link LOCALE_PROPS} is what asks for them, and it is a request of its own
     * rather than an `only` on the write: a partial response is only recognised
     * as one when it names the component the client is on, which a write that
     * ends in a redirect cannot promise. It is a background visit because the
     * reader is not waiting on it — the catalog swap above already re-rendered
     * everything the *client* translates.
     */
    async function updateLocale(next: AppLocale): Promise<void> {
        const messages = await fetchCatalog(next);
        setMessages(next, messages);

        router.patch(
            update().url,
            { locale: next },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () =>
                    router.reload({ ...backgroundVisit, only: LOCALE_PROPS }),
            },
        );
    }

    return { locale, updateLocale };
}
