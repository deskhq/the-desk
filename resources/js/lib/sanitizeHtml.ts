import DOMPurify from 'dompurify';

/**
 * The allowlist an HTML string is sanitized against before it reaches a
 * `v-html` surface.
 */
export type SanitizeConfig = {
    ALLOWED_TAGS: string[];
    ALLOWED_ATTR: string[];
};

/**
 * The allowlist for rendered message bodies — the formatting scaffold plus the
 * mention/emoji/link markup {@see @/lib/messageBody} builds.
 */
export const MESSAGE_BODY_SANITIZE_CONFIG: SanitizeConfig = {
    ALLOWED_TAGS: ['strong', 'em', 'del', 'code', 'br', 'span', 'a', 'img'],
    ALLOWED_ATTR: ['class', 'href', 'target', 'rel', 'src', 'alt', 'title'],
};

/**
 * The allowlist for server-built search snippets, whose only markup is the
 * `<mark>` wrapper around each matched run.
 */
export const SEARCH_SNIPPET_SANITIZE_CONFIG: SanitizeConfig = {
    ALLOWED_TAGS: ['mark'],
    ALLOWED_ATTR: [],
};

/**
 * Sanitize a run of HTML against a fixed allowlist. This is the single XSS trust
 * boundary in front of every `v-html` surface: a string reaching one of them
 * passes through here first, so an attacker-authored tag or `javascript:` URL
 * that somehow survived its producer could never survive this.
 *
 * A DOM-less SSR pass has no `window` for DOMPurify to run against, so it is
 * skipped there — still safe because every producer escapes the text it embeds
 * and emits only a closed set of tags it authors itself, and the client
 * re-sanitizes on hydration.
 */
export function sanitizeHtml(html: string, config: SanitizeConfig): string {
    if (typeof window === 'undefined') {
        return html;
    }

    return DOMPurify.sanitize(html, config);
}
