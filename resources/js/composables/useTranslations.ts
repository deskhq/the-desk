import { computed } from 'vue';
import { i18n, translate } from '@/lib/i18n';

/**
 * Translate copy from `<script setup>`, where the global `$t` template helper is
 * not available. Reads the reactive catalog, so callers re-run when the locale
 * changes.
 */
export function useTranslations() {
    const locale = computed(() => i18n.locale);

    function t(
        key: string,
        replacements?: Record<string, string | number>,
    ): string {
        return translate(key, replacements);
    }

    return { t, locale };
}
