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
 * The allowlist for the two-factor enrolment QR code, whose SVG is rendered
 * server-side by Fortify. Only the four elements and the geometry/paint
 * attributes that renderer emits are permitted, so nothing scriptable (`script`,
 * `foreignObject`, an `on*` handler) survives even though the source is trusted.
 * Attribute names are matched lower-cased, hence `viewbox`.
 */
export const QR_CODE_SANITIZE_CONFIG: SanitizeConfig = {
    ALLOWED_TAGS: ['svg', 'g', 'rect', 'path'],
    ALLOWED_ATTR: [
        'xmlns',
        'version',
        'width',
        'height',
        'viewbox',
        'transform',
        'x',
        'y',
        'd',
        'fill',
        'fill-rule',
    ],
};

/**
 * Every allowlist a `v-html` surface may be rendered under, keyed by the
 * `variant` {@see @/components/SafeHtml} takes. Enumerating them here keeps the
 * choice of allowlist reviewable in one place instead of spread across the call
 * sites.
 */
export const SANITIZE_CONFIGS = {
    messageBody: MESSAGE_BODY_SANITIZE_CONFIG,
    searchSnippet: SEARCH_SNIPPET_SANITIZE_CONFIG,
    qrCode: QR_CODE_SANITIZE_CONFIG,
} as const satisfies Record<string, SanitizeConfig>;

/** The name of one of the {@see SANITIZE_CONFIGS} allowlists. */
export type SanitizeVariant = keyof typeof SANITIZE_CONFIGS;

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
