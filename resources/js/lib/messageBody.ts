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
 * A parsed piece of a message body: either a run of safe HTML (escaped text with
 * mention pills and `<br>` line breaks) or a bare URL. Splitting the body this
 * way lets the timeline render each link as a real element it can hang an
 * interactive affordance (the unfurl hover card) off of, which a single
 * `v-html` string can't support.
 */
export type MessageBodySegment =
    | { kind: 'html'; html: string }
    | { kind: 'link'; href: string }
    | { kind: 'mention'; id: string; name: string };

// The highlighted-pill styling for a resolved mention, shared by the interactive
// segment renderer and the flat-HTML {@see renderMessageBody}.
const MENTION_PILL_CLASS =
    'rounded px-1 py-0.5 font-medium text-blue-700 bg-blue-500/10 dark:text-blue-300 dark:bg-blue-400/15';

function mentionPillHtml(name: string): string {
    return `<span class="${MENTION_PILL_CLASS}">@${escapeHtml(name)}</span>`;
}

// Escape a run of text for HTML and preserve its newlines as `<br>`.
function escapeInline(text: string): string {
    return escapeHtml(text).replace(/\n/g, '<br>');
}

/**
 * Split a run of message text (no URLs) into ordered segments: escaped HTML runs
 * and standalone `mention` segments the timeline can wrap in a profile hover
 * card.
 *
 * Only mentions whose id is present in `resolved` become a `mention` segment;
 * any other well-formed token falls back to its plain `@Name` text so a spoofed
 * token for a non-member can never masquerade as a resolved mention.
 */
function tokenizeInlineText(
    text: string,
    resolved: Set<string>,
): MessageBodySegment[] {
    const segments: MessageBodySegment[] = [];
    // A fresh regex per call keeps the shared `lastIndex` from leaking between
    // invocations of this stateful global pattern.
    const pattern = new RegExp(MENTION_PATTERN.source, 'g');
    let lastIndex = 0;
    let match: RegExpExecArray | null;

    const pushText = (value: string) => {
        if (value !== '') {
            segments.push({ kind: 'html', html: escapeInline(value) });
        }
    };

    while ((match = pattern.exec(text)) !== null) {
        const [raw, name, id] = match;

        pushText(text.slice(lastIndex, match.index));

        if (resolved.has(id)) {
            segments.push({ kind: 'mention', id, name });
        } else {
            segments.push({ kind: 'html', html: `@${escapeHtml(name)}` });
        }

        lastIndex = match.index + raw.length;
    }

    pushText(text.slice(lastIndex));

    return segments;
}

function linkHtml(href: string): string {
    return `<a href="${href}" target="_blank" rel="noopener noreferrer nofollow" class="text-primary underline underline-offset-2 hover:no-underline">${href}</a>`;
}

/**
 * Split a raw message body into ordered HTML and link segments. Text between
 * URLs (carrying mentions and newlines) becomes escaped HTML; each bare URL
 * becomes a `link` segment with any trailing prose punctuation split back out
 * as its own HTML segment, mirroring the autolink rule the server unfurls by.
 */
export function tokenizeMessageBody(
    body: string,
    mentions: Mention[] = [],
): MessageBodySegment[] {
    const segments: MessageBodySegment[] = [];
    const resolved = new Set(mentions.map((mention) => mention.id));
    // A fresh regex per call keeps the shared `lastIndex` from leaking between
    // invocations of this stateful global pattern.
    const pattern = new RegExp(URL_PATTERN.source, 'gi');
    let lastIndex = 0;
    let match: RegExpExecArray | null;

    while ((match = pattern.exec(body)) !== null) {
        if (match.index > lastIndex) {
            segments.push(
                ...tokenizeInlineText(
                    body.slice(lastIndex, match.index),
                    resolved,
                ),
            );
        }

        const raw = match[0];
        const trailing = raw.match(TRAILING_PUNCTUATION)?.[0] ?? '';
        segments.push({
            kind: 'link',
            href: raw.slice(0, raw.length - trailing.length),
        });

        if (trailing !== '') {
            segments.push({ kind: 'html', html: escapeHtml(trailing) });
        }

        lastIndex = match.index + raw.length;
    }

    if (lastIndex < body.length) {
        segments.push(...tokenizeInlineText(body.slice(lastIndex), resolved));
    }

    return segments;
}

/**
 * Render a raw message body into safe HTML with mention pills, autolinked bare
 * URLs, and newlines preserved as `<br>`. Intended for `v-html` where an
 * interactive per-link affordance isn't needed (compact reply/forward quotes);
 * the main timeline renders {@see tokenizeMessageBody} instead so it can wrap
 * links in a hover card.
 */
export function renderMessageBody(
    body: string,
    mentions: Mention[] = [],
): string {
    return tokenizeMessageBody(body, mentions)
        .map((segment) => {
            if (segment.kind === 'link') {
                return linkHtml(segment.href);
            }

            if (segment.kind === 'mention') {
                return mentionPillHtml(segment.name);
            }

            return segment.html;
        })
        .join('');
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
