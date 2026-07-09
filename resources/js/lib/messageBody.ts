import type { Mention } from '@/types';

const HTML_ESCAPES: Record<string, string> = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
};

function escapeHtml(text: string): string {
    return text.replace(/[&<>"']/g, (char) => HTML_ESCAPES[char]);
}

// The composer stores each resolved mention as a `@[Display Name](user-id)`
// token. None of its literal characters are altered by HTML escaping, so the
// token survives escapeHtml intact and can be matched afterwards.
const MENTION_PATTERN = /@\[([^\]]+)\]\(([0-9a-fA-F-]{36})\)/g;

// http/https URLs, consuming everything up to whitespace. Any escaped `<`
// becomes `&lt;` before this runs, so it can never re-open a tag.
const URL_PATTERN = /\bhttps?:\/\/[^\s]+/gi;

// Punctuation that commonly trails a URL in prose and shouldn't be linked.
const TRAILING_PUNCTUATION = /[.,!?;:'")\]]+$/;

/**
 * Render a raw message body into safe HTML: HTML is escaped first (so user
 * input can never inject markup), then mention tokens are highlighted, bare
 * URLs are autolinked, and newlines are preserved as `<br>`. The result is
 * intended for `v-html`.
 *
 * Only mentions whose id is present in `mentions` render as a highlighted pill;
 * any other well-formed token falls back to its plain `@Name` text so a spoofed
 * token for a non-member can never masquerade as a resolved mention.
 */
export function renderMessageBody(
    body: string,
    mentions: Mention[] = [],
): string {
    const escaped = escapeHtml(body);
    const resolved = new Set(mentions.map((mention) => mention.id));

    const withMentions = escaped.replace(
        MENTION_PATTERN,
        (_match, name: string, id: string) =>
            resolved.has(id)
                ? `<span class="rounded px-1 py-0.5 font-medium text-blue-700 bg-blue-500/10 dark:text-blue-300 dark:bg-blue-400/15">@${name}</span>`
                : `@${name}`,
    );

    const linked = withMentions.replace(URL_PATTERN, (match) => {
        const trailing = match.match(TRAILING_PUNCTUATION)?.[0] ?? '';
        const href = match.slice(0, match.length - trailing.length);

        return `<a href="${href}" target="_blank" rel="noopener noreferrer nofollow" class="text-primary underline underline-offset-2 hover:no-underline">${href}</a>${trailing}`;
    });

    return linked.replace(/\n/g, '<br>');
}

/**
 * Flatten a raw message body to a single line of plain text for a compact quote
 * preview: mention tokens collapse to their `@Name` text and runs of whitespace
 * (including newlines) become single spaces. Returned as plain text, never HTML,
 * so it is safe to render inside an interactive quote without markup injection.
 */
export function messageBodyPreview(body: string): string {
    return body
        .replace(MENTION_PATTERN, (_match, name: string) => `@${name}`)
        .replace(/\s+/g, ' ')
        .trim();
}
