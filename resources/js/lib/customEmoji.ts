/**
 * A workspace's custom emoji as a flat `name -> image URL` map, shared from the
 * server (see `HandleInertiaRequests`) and read wherever a `:name:` shortcode is
 * rendered — message bodies, reaction pills, and the picker's "Custom" section.
 */
export type CustomEmojiMap = Record<string, string>;

/**
 * A single custom emoji, as surfaced to the picker "Custom" strip.
 */
export interface CustomEmojiEntry {
    name: string;
    url: string;
}

// A `:name:` shortcode: kebab-case, matching the backend's stored `name`. The
// anchored variant tests a whole token (a reaction value); the global variant
// finds tokens embedded in message text.
const SHORTCODE = '[a-z0-9]+(?:-[a-z0-9]+)*';
export const SHORTCODE_TOKEN_PATTERN = new RegExp(`^:(${SHORTCODE}):$`);
export const SHORTCODE_PATTERN = new RegExp(`:(${SHORTCODE}):`, 'g');

/**
 * Resolve a bare emoji name to its image URL, or null when the workspace has no
 * such emoji (e.g. it was revoked) — callers fall back to the literal `:name:`.
 */
export function resolveCustomEmoji(
    name: string,
    map: CustomEmojiMap,
): string | null {
    return Object.prototype.hasOwnProperty.call(map, name) ? map[name] : null;
}

/**
 * When `value` is a whole `:name:` shortcode token that resolves in the map,
 * return its `{ name, url }`; otherwise null. Used by reaction pills to decide
 * whether to render an image or the raw (unicode or unresolved) string.
 */
export function parseCustomEmojiToken(
    value: string,
    map: CustomEmojiMap,
): CustomEmojiEntry | null {
    const match = SHORTCODE_TOKEN_PATTERN.exec(value);

    if (match === null) {
        return null;
    }

    const url = resolveCustomEmoji(match[1], map);

    return url === null ? null : { name: match[1], url };
}

/**
 * The workspace's custom emoji as a sorted list for the picker strip.
 */
export function customEmojiList(map: CustomEmojiMap): CustomEmojiEntry[] {
    return Object.keys(map)
        .sort()
        .map((name) => ({ name, url: map[name] }));
}

/**
 * Filter the custom emoji list by a search query, matching against the `:name:`.
 * A leading colon in the query is ignored so typing `:part` and `part` both work.
 */
export function searchCustomEmoji(
    query: string,
    map: CustomEmojiMap,
): CustomEmojiEntry[] {
    const needle = query.trim().replace(/^:/, '').toLowerCase();

    if (needle === '') {
        return customEmojiList(map);
    }

    return customEmojiList(map).filter((entry) => entry.name.includes(needle));
}
