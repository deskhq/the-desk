import MarkdownIt from 'markdown-it';
import { resolveCustomEmoji, SHORTCODE_PATTERN } from '@/lib/customEmoji';
import type { CustomEmojiMap } from '@/lib/customEmoji';
import { MESSAGE_BODY_SANITIZE_CONFIG, sanitizeHtml } from '@/lib/sanitizeHtml';
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

/**
 * The composer stores each resolved mention as a `@[Display Name](user-id)`
 * token, and each user-group mention as `@[handle](group:group-id)`. None of
 * their literal characters are altered by HTML escaping, so a token survives
 * escapeHtml intact and can be matched afterwards. The `group:` prefix is what
 * tells the two apart; its absence is the original user token, so bodies
 * written before groups existed still resolve.
 */
const MENTION_PATTERN = /@\[([^\]]+)\]\((group:)?([0-9a-fA-F-]{36})\)/g;

/**
 * http/https URLs, consuming everything up to whitespace. Any escaped `<`
 * becomes `&lt;` before this runs, so it can never re-open a tag.
 */
const URL_PATTERN = /\bhttps?:\/\/[^\s]+/gi;

/** Punctuation that commonly trails a URL in prose and shouldn't be linked. */
const TRAILING_PUNCTUATION = /[.,!?;:'")\]]+$/;

/**
 * The restricted inline Markdown parser. Built from the `zero` preset (every
 * rule off) with only the three inline mark rules re-enabled, so it recognises
 * `**bold**`/`*italic*`/`_italic_`, `~~strike~~`, and `` `code` `` and nothing
 * else. Crucially the `link`/`image` rules stay off, so `[text](url)` is never
 * interpreted as a link (which would collide with the `@[Name](id)` mention
 * token) and no arbitrary link authoring leaks in. We use it only as a
 * tokenizer: its text runs still flow through our own mention/emoji/URL
 * tokenizer below, and we build every tag ourselves — the parser never emits
 * HTML.
 */
const markdown = new MarkdownIt('zero').enable([
    'emphasis',
    'strikethrough',
    'backticks',
]);

/**
 * The inline marks that can wrap a segment, ordered outermost-first. Inline code
 * is handled separately (it is baked into an `html` segment because it also
 * suppresses inner parsing), so it is not part of this set.
 */
export type InlineMark = 'strong' | 'em' | 'del';

const MARK_TAG: Record<InlineMark, string> = {
    strong: 'strong',
    em: 'em',
    del: 'del',
};

/** Wrap a run of HTML in the given marks, outermost mark first. */
function wrapMarks(inner: string, marks: InlineMark[]): string {
    let html = inner;

    for (let index = marks.length - 1; index >= 0; index -= 1) {
        const tag = MARK_TAG[marks[index]];
        html = `<${tag}>${html}</${tag}>`;
    }

    return html;
}

/**
 * Sanitize a run of emitted HTML against the message-body allowlist — the
 * formatting scaffold plus the mention/emoji/link markup this module builds.
 * This is the XSS trust boundary: every HTML string we hand to a `v-html`
 * surface passes through here first, so consumers must never re-sanitize with
 * an allowlist of their own (a narrower one silently strips formatting).
 */
function sanitize(html: string): string {
    return sanitizeHtml(html, MESSAGE_BODY_SANITIZE_CONFIG);
}

/**
 * A parsed piece of a message body: a run of safe HTML (escaped text with
 * formatting marks, mention pills, and `<br>` line breaks), or a mention,
 * emoji, or bare URL lifted out as its own interactive segment. Splitting the
 * body this way lets the timeline render each link/mention as a real element it
 * can hang an interactive affordance (the unfurl hover card, the profile card)
 * off of, which a single `v-html` string can't support. The interactive
 * segments carry the inline marks wrapping them so formatting composes around
 * them (e.g. a bold mention).
 */
export type MessageBodySegment =
    | { kind: 'html'; html: string }
    | { kind: 'link'; href: string; marks?: InlineMark[] }
    | { kind: 'mention'; id: string; name: string; marks?: InlineMark[] }
    | { kind: 'groupMention'; id: string; name: string; marks?: InlineMark[] }
    | { kind: 'emoji'; name: string; url: string; marks?: InlineMark[] };

/**
 * The minimum shape needed to resolve a `group:<id>` token: the workspace's
 * mentionable groups, as shared on every in-workspace request. Structurally
 * satisfied by `App.Data.UserGroupData`.
 */
export type ResolvableGroup = { id: string };

/**
 * The highlighted-pill styling for a resolved mention, shared by the interactive
 * segment renderer and the flat-HTML {@see renderMessageBody}.
 */
const MENTION_PILL_CLASS =
    'rounded px-1 py-0.5 font-medium text-blue-700 bg-blue-500/10 dark:text-blue-300 dark:bg-blue-400/15';

/**
 * The same pill in a distinct hue, so a mention that reaches a whole group reads
 * as different from one that names a single person at a glance.
 */
const GROUP_PILL_CLASS =
    'rounded px-1 py-0.5 font-medium text-violet-700 bg-violet-500/10 dark:text-violet-300 dark:bg-violet-400/15';

function mentionPillHtml(name: string): string {
    return `<span class="${MENTION_PILL_CLASS}">@${escapeHtml(name)}</span>`;
}

function groupPillHtml(name: string): string {
    return `<span class="${GROUP_PILL_CLASS}">@${escapeHtml(name)}</span>`;
}

/** Escape a run of text for HTML and preserve its newlines as `<br>`. */
function escapeInline(text: string): string {
    return escapeHtml(text).replace(/\n/g, '<br>');
}

/**
 * Tokenize a run of plain text (a single mark context — the same marks apply to
 * all of it) into ordered segments: escaped-and-sanitized HTML runs carrying the
 * marks baked in, plus `link`/`mention`/`emoji` interactive segments carrying
 * the marks as data so the caller can wrap them. This is where mentions, custom
 * emoji, and bare URLs stay first-class, exactly as before formatting existed —
 * the marks simply compose around them.
 */
function tokenizeTextRun(
    text: string,
    marks: InlineMark[],
    resolved: Set<string>,
    emojiMap: CustomEmojiMap,
    resolvedGroups: Set<string>,
): MessageBodySegment[] {
    const segments: MessageBodySegment[] = [];

    const pushHtml = (value: string): void => {
        if (value !== '') {
            segments.push({
                kind: 'html',
                html: sanitize(wrapMarks(escapeInline(value), marks)),
            });
        }
    };

    const withMarks = <T extends object>(segment: T): T => {
        return marks.length > 0 ? { ...segment, marks: [...marks] } : segment;
    };

    /** Emoji shortcodes inside a run that already has no URL or mention. */
    const pushEmojiRun = (value: string): void => {
        if (value === '') {
            return;
        }

        const pattern = new RegExp(SHORTCODE_PATTERN.source, 'g');
        let lastIndex = 0;
        let match: RegExpExecArray | null;

        while ((match = pattern.exec(value)) !== null) {
            const url = resolveCustomEmoji(match[1], emojiMap);

            // Unresolved shortcode: leave it in the text stream so it renders as
            // the literal `:name:` (the graceful fallback).
            if (url === null) {
                continue;
            }

            pushHtml(value.slice(lastIndex, match.index));
            segments.push(withMarks({ kind: 'emoji', name: match[1], url }));
            lastIndex = match.index + match[0].length;
        }

        pushHtml(value.slice(lastIndex));
    };

    /**
     * Mentions within a run that has no URL. Only ids present in `resolved` (for
     * people) or `resolvedGroups` (for user groups) become interactive; any other
     * well-formed token falls back to plain `@Name` text so a spoofed token can
     * never masquerade as a real mention. A group deleted since the message was
     * sent lands in that same fallback.
     */
    const pushInline = (chunk: string): void => {
        const pattern = new RegExp(MENTION_PATTERN.source, 'g');
        let lastIndex = 0;
        let match: RegExpExecArray | null;

        while ((match = pattern.exec(chunk)) !== null) {
            const [raw, name, prefix, id] = match;
            const isGroup = prefix !== undefined;

            pushEmojiRun(chunk.slice(lastIndex, match.index));

            if (isGroup && resolvedGroups.has(id)) {
                segments.push(withMarks({ kind: 'groupMention', id, name }));
            } else if (!isGroup && resolved.has(id)) {
                segments.push(withMarks({ kind: 'mention', id, name }));
            } else {
                pushHtml(`@${name}`);
            }

            lastIndex = match.index + raw.length;
        }

        pushEmojiRun(chunk.slice(lastIndex));
    };

    // Split URLs out first, then resolve mentions/emoji within the gaps —
    // mirroring the autolink rule the server unfurls by.
    const pattern = new RegExp(URL_PATTERN.source, 'gi');
    let lastIndex = 0;
    let match: RegExpExecArray | null;

    while ((match = pattern.exec(text)) !== null) {
        if (match.index > lastIndex) {
            pushInline(text.slice(lastIndex, match.index));
        }

        const raw = match[0];
        const trailing = raw.match(TRAILING_PUNCTUATION)?.[0] ?? '';
        segments.push(
            withMarks({
                kind: 'link',
                href: raw.slice(0, raw.length - trailing.length),
            }),
        );

        if (trailing !== '') {
            pushHtml(trailing);
        }

        lastIndex = match.index + raw.length;
    }

    if (lastIndex < text.length) {
        pushInline(text.slice(lastIndex));
    }

    return segments;
}

/**
 * Build the `html` segment for an inline `` `code` `` span. Its content renders
 * literally — no mention, emoji, or URL inside it resolves — so it never flows
 * through {@see tokenizeTextRun}; the text is escaped as-is and wrapped in
 * `<code>` plus any marks the code span itself sits inside.
 */
function codeSegment(content: string, marks: InlineMark[]): MessageBodySegment {
    const inner = `<code>${escapeInline(content)}</code>`;

    return { kind: 'html', html: sanitize(wrapMarks(inner, marks)) };
}

/**
 * Split a raw message body into ordered segments, applying the inline Markdown
 * marks (`**bold**`, `*italic*`/`_italic_`, `~~strike~~`, `` `code` ``) around
 * the existing mention/emoji/URL segments. The Markdown parser is used only as
 * a tokenizer to find the mark boundaries; the text between marks still flows
 * through {@see tokenizeTextRun}, and inline code suppresses inner parsing.
 */
export function tokenizeMessageBody(
    body: string,
    mentions: Mention[] = [],
    emojiMap: CustomEmojiMap = {},
    groups: ResolvableGroup[] = [],
): MessageBodySegment[] {
    const resolved = new Set(mentions.map((mention) => mention.id));
    const resolvedGroups = new Set(groups.map((group) => group.id));
    const segments: MessageBodySegment[] = [];
    const tokens = markdown.parseInline(body, {})[0]?.children ?? [];

    // The active marks, pushed/popped as the parser opens and closes them. A
    // text run is flushed on every boundary so each segment carries exactly the
    // marks in force over it.
    const marks: InlineMark[] = [];
    let buffer = '';

    const flush = (): void => {
        if (buffer !== '') {
            segments.push(
                ...tokenizeTextRun(
                    buffer,
                    marks,
                    resolved,
                    emojiMap,
                    resolvedGroups,
                ),
            );
            buffer = '';
        }
    };

    for (const token of tokens) {
        if (token.type === 'text') {
            buffer += token.content;
        } else if (token.type === 'strong_open') {
            flush();
            marks.push('strong');
        } else if (token.type === 'strong_close') {
            flush();
            marks.pop();
        } else if (token.type === 'em_open') {
            flush();
            marks.push('em');
        } else if (token.type === 'em_close') {
            flush();
            marks.pop();
        } else if (token.type === 's_open') {
            flush();
            marks.push('del');
        } else if (token.type === 's_close') {
            flush();
            marks.pop();
        } else if (token.type === 'code_inline') {
            flush();
            segments.push(codeSegment(token.content, marks));
        }
    }

    flush();

    return segments;
}

function linkHtml(href: string): string {
    // A bare URL runs to the next whitespace, so it can carry a `"` that would
    // otherwise break out of the attribute (and inject markup) on the DOM-less
    // SSR path where DOMPurify is skipped. Escape it for the attribute and the
    // visible text, matching mentionPillHtml/emojiImgHtml.
    const safe = escapeHtml(href);

    return `<a href="${safe}" target="_blank" rel="noopener noreferrer nofollow" class="text-primary underline underline-offset-2 hover:no-underline">${safe}</a>`;
}

/**
 * The shared inline-image markup for a resolved custom emoji. The url comes from
 * the trusted server-shared map and the name is regex-constrained kebab-case, so
 * both are safe to interpolate; the alt is still escaped defensively.
 */
const EMOJI_IMG_CLASS =
    'custom-emoji inline-block h-[1.35em] w-[1.35em] align-text-bottom';

function emojiImgHtml(name: string, url: string): string {
    return `<img src="${escapeHtml(url)}" alt=":${escapeHtml(name)}:" class="${EMOJI_IMG_CLASS}">`;
}

/**
 * Render a raw message body into safe HTML with inline formatting, mention
 * pills, autolinked bare URLs, and newlines preserved as `<br>`. Intended for
 * `v-html` where an interactive per-link affordance isn't needed (compact
 * reply/forward quotes, quick-switcher, search results); the main timeline
 * renders {@see tokenizeMessageBody} instead so it can wrap links in a hover
 * card. The assembled HTML passes through DOMPurify one final time.
 */
export function renderMessageBody(
    body: string,
    mentions: Mention[] = [],
    emojiMap: CustomEmojiMap = {},
    groups: ResolvableGroup[] = [],
): string {
    const html = tokenizeMessageBody(body, mentions, emojiMap, groups)
        .map((segment) => {
            if (segment.kind === 'link') {
                return wrapMarks(linkHtml(segment.href), segment.marks ?? []);
            }

            if (segment.kind === 'mention') {
                return wrapMarks(
                    mentionPillHtml(segment.name),
                    segment.marks ?? [],
                );
            }

            if (segment.kind === 'groupMention') {
                return wrapMarks(
                    groupPillHtml(segment.name),
                    segment.marks ?? [],
                );
            }

            if (segment.kind === 'emoji') {
                return wrapMarks(
                    emojiImgHtml(segment.name, segment.url),
                    segment.marks ?? [],
                );
            }

            return segment.html;
        })
        .join('');

    return sanitize(html);
}

/**
 * The message body as the author typed it, ready for the clipboard: mention and
 * group tokens collapse to their visible `@Name` text, while newlines and the
 * inline Markdown syntax are kept verbatim — they are the text the author wrote.
 */
export function messageBodyCopyText(body: string): string {
    return body.replace(MENTION_PATTERN, (_match, name: string) => `@${name}`);
}

/**
 * Flatten a raw message body to a single line of plain text for a compact quote
 * preview: inline Markdown syntax is stripped (marks removed, inline-code
 * content kept literal), mention tokens collapse to their `@Name` text, and runs
 * of whitespace (including newlines) become single spaces. Returned as plain
 * text, never HTML, so it is safe to render inside an interactive quote without
 * markup injection.
 */
export function messageBodyPreview(body: string): string {
    const tokens = markdown.parseInline(body, {})[0]?.children ?? [];
    let text = '';

    for (const token of tokens) {
        if (token.type === 'text' || token.type === 'code_inline') {
            text += token.content;
        }
    }

    return text
        .replace(MENTION_PATTERN, (_match, name: string) => `@${name}`)
        .replace(/\s+/g, ' ')
        .trim();
}
