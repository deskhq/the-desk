import { i18n } from './i18n';

/**
 * Format a number in the active locale, so grouping separators and digits follow
 * the user's language (e.g. "1,234" in English, "1 234" in French).
 */
export function formatNumber(
    value: number,
    locale: string = i18n.locale,
): string {
    return new Intl.NumberFormat(locale).format(value);
}
