import { reactive } from 'vue';

/**
 * A locale's message catalog: source string (the key) → translated line. Keys
 * are the English source strings, matching Laravel's JSON translation files, so
 * a missing entry falls back to the key and reads as English.
 */
export type Messages = Record<string, string>;

type I18nState = {
    locale: string;
    messages: Messages;
};

/**
 * The reactive translation state. Reading `messages` inside a render tracks it,
 * so every `$t()` / `t()` call re-renders when the catalog is swapped.
 *
 * It is seeded in `app.ts` (via `setMessages`) from the server-shared `locale`
 * and `translations` props before the first render — on both the SSR pass and
 * the client — so the initial paint is already in the right language, with no
 * flash of English on refresh.
 */
export const i18n = reactive<I18nState>({
    locale: 'en',
    messages: {},
});

type Replacements = Record<string, string | number>;

/**
 * Apply Laravel's placeholder replacement to a line: `:name`, `:Name` (ucfirst),
 * and `:NAME` (uppercase). Longer keys are replaced first so `:name` never eats
 * into `:name_long`.
 */
function replacePlaceholders(line: string, replacements: Replacements): string {
    return Object.keys(replacements)
        .sort((a, b) => b.length - a.length)
        .reduce((result, token) => {
            const value = String(replacements[token]);
            const upper = value.toUpperCase();
            const ucfirst = value.charAt(0).toUpperCase() + value.slice(1);

            return result
                .replaceAll(`:${token.toUpperCase()}`, upper)
                .replaceAll(
                    `:${token.charAt(0).toUpperCase()}${token.slice(1)}`,
                    ucfirst,
                )
                .replaceAll(`:${token}`, value);
        }, line);
}

/**
 * Translate a message key against the active catalog, falling back to the key
 * itself (Laravel's JSON behaviour), then interpolating any replacements.
 */
export function translate(
    key: string,
    replacements: Replacements = {},
): string {
    // Depend on the active locale AND the catalog so every `$t`/`t` caller
    // re-renders the instant the language is switched at runtime — not only on
    // the next full page load.
    const line = i18n.locale && key in i18n.messages ? i18n.messages[key] : key;

    return replacePlaceholders(line, replacements);
}

/**
 * Replace the active locale and catalog, e.g. after the user switches language.
 */
export function setMessages(locale: string, messages: Messages): void {
    i18n.locale = locale;
    i18n.messages = messages;
}

/**
 * Fetch a locale's catalog from the cacheable endpoint. The browser caches the
 * response, so switching back to a locale is served from cache.
 */
export async function fetchCatalog(locale: string): Promise<Messages> {
    // `no-store` bypasses the HTTP cache so a language switch always loads the
    // current catalog — never a stale copy the browser cached earlier (a
    // catalog that was empty before its translations existed, say).
    const response = await fetch(`/locales/${locale}.json`, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
    });

    return response.json() as Promise<Messages>;
}
