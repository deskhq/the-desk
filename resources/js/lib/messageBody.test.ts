// @vitest-environment jsdom
// DOMPurify (the sanitize boundary in messageBody.ts) needs a DOM; jsdom gives
// this file a `window` so sanitization runs exactly as it does in the browser.
import { describe, expect, it } from 'vitest';
import type { CustomEmojiMap } from '@/lib/customEmoji';
import {
    messageBodyCopyText,
    messageBodyPreview,
    renderMessageBody,
    tokenizeMessageBody,
} from '@/lib/messageBody';
import type { Mention } from '@/types';

const alice: Mention = {
    id: 'a1b2c3d4-e5f6-7890-1234-567890abcdef',
    name: 'Alice',
};

const devs = { id: 'b1c2d3e4-f5a6-7890-1234-567890abcdef' };

const emojiMap: CustomEmojiMap = {
    shipit: 'https://cdn.test/shipit.png',
    'party-otter': 'https://cdn.test/party-otter.png',
};

describe('tokenizeMessageBody', () => {
    it('returns a single html segment for plain text', () => {
        expect(tokenizeMessageBody('hello world')).toEqual([
            { kind: 'html', html: 'hello world' },
        ]);
    });

    it('escapes html in text segments', () => {
        expect(tokenizeMessageBody('<b>hi</b>')).toEqual([
            { kind: 'html', html: '&lt;b&gt;hi&lt;/b&gt;' },
        ]);
    });

    it('splits a URL out as its own link segment', () => {
        expect(tokenizeMessageBody('see https://example.com now')).toEqual([
            { kind: 'html', html: 'see ' },
            { kind: 'link', href: 'https://example.com' },
            { kind: 'html', html: ' now' },
        ]);
    });

    it('keeps trailing punctuation out of the link href', () => {
        expect(tokenizeMessageBody('visit https://example.com/a.')).toEqual([
            { kind: 'html', html: 'visit ' },
            { kind: 'link', href: 'https://example.com/a' },
            { kind: 'html', html: '.' },
        ]);
    });

    it('tokenizes several links in order', () => {
        expect(
            tokenizeMessageBody('https://a.test and https://b.test'),
        ).toEqual([
            { kind: 'link', href: 'https://a.test' },
            { kind: 'html', html: ' and ' },
            { kind: 'link', href: 'https://b.test' },
        ]);
    });

    it('splits a resolved mention out as its own segment', () => {
        expect(
            tokenizeMessageBody(`hi @[Alice](${alice.id})`, [alice]),
        ).toEqual([
            { kind: 'html', html: 'hi ' },
            { kind: 'mention', id: alice.id, name: 'Alice' },
        ]);
    });

    it('falls back to plain text for an unresolved mention', () => {
        expect(tokenizeMessageBody(`hi @[Ghost](${alice.id})`)).toEqual([
            { kind: 'html', html: 'hi ' },
            { kind: 'html', html: '@Ghost' },
        ]);
    });

    it('lifts a resolved :name: shortcode into an emoji segment', () => {
        expect(tokenizeMessageBody('ship it :shipit:', [], emojiMap)).toEqual([
            { kind: 'html', html: 'ship it ' },
            {
                kind: 'emoji',
                name: 'shipit',
                url: 'https://cdn.test/shipit.png',
            },
        ]);
    });

    it('leaves an unresolved (revoked) shortcode as literal text', () => {
        expect(tokenizeMessageBody('aw :gone: no', [], emojiMap)).toEqual([
            { kind: 'html', html: 'aw :gone: no' },
        ]);
    });

    it('resolves shortcodes around an unresolved one', () => {
        expect(
            tokenizeMessageBody('a :gone: b :shipit: c', [], emojiMap),
        ).toEqual([
            { kind: 'html', html: 'a :gone: b ' },
            {
                kind: 'emoji',
                name: 'shipit',
                url: 'https://cdn.test/shipit.png',
            },
            { kind: 'html', html: ' c' },
        ]);
    });

    it('composes emoji with mentions in the same run', () => {
        expect(
            tokenizeMessageBody(
                `:shipit: @[Alice](${alice.id})`,
                [alice],
                emojiMap,
            ),
        ).toEqual([
            {
                kind: 'emoji',
                name: 'shipit',
                url: 'https://cdn.test/shipit.png',
            },
            { kind: 'html', html: ' ' },
            { kind: 'mention', id: alice.id, name: 'Alice' },
        ]);
    });

    it('does not treat shortcodes as emoji without a map', () => {
        expect(tokenizeMessageBody('plain :shipit: text')).toEqual([
            { kind: 'html', html: 'plain :shipit: text' },
        ]);
    });
});

describe('renderMessageBody', () => {
    it('autolinks a bare URL into an anchor', () => {
        const html = renderMessageBody('see https://example.com');

        expect(html).toContain('<a href="https://example.com"');
        expect(html).toContain('target="_blank"');
    });

    it('preserves newlines as <br>', () => {
        expect(renderMessageBody('a\nb')).toBe('a<br>b');
    });

    it('renders a resolved mention as a highlighted pill', () => {
        const html = renderMessageBody(`hi @[Alice](${alice.id})`, [alice]);

        expect(html).toContain('>@Alice<');
        expect(html).toContain('text-blue-700');
    });

    it('renders a resolved custom emoji as an <img>', () => {
        const html = renderMessageBody('go :shipit:', [], emojiMap);

        expect(html).toContain('<img src="https://cdn.test/shipit.png"');
        expect(html).toContain('alt=":shipit:"');
    });

    it('leaves a revoked shortcode as literal text', () => {
        expect(renderMessageBody('aw :gone:', [], emojiMap)).toBe('aw :gone:');
    });
});

describe('inline formatting marks', () => {
    it('renders **bold** as a <strong> run', () => {
        expect(renderMessageBody('a **b** c')).toBe('a <strong>b</strong> c');
    });

    it('renders *italic* and _italic_ as <em> runs', () => {
        expect(renderMessageBody('a *b* _c_')).toBe('a <em>b</em> <em>c</em>');
    });

    it('renders ~~strike~~ as a <del> run', () => {
        expect(renderMessageBody('a ~~b~~ c')).toBe('a <del>b</del> c');
    });

    it('renders `code` as a <code> run', () => {
        expect(renderMessageBody('a `b` c')).toBe('a <code>b</code> c');
    });

    it('nests marks, outermost first', () => {
        expect(renderMessageBody('**_x_**')).toBe(
            '<strong><em>x</em></strong>',
        );
    });

    it('escapes html inside a formatted run', () => {
        expect(renderMessageBody('**<b>**')).toBe('<strong>&lt;b&gt;</strong>');
    });

    it('marks compose around a resolved mention on the interactive path', () => {
        expect(
            tokenizeMessageBody(`**@[Alice](${alice.id})**`, [alice]),
        ).toEqual([
            { kind: 'mention', id: alice.id, name: 'Alice', marks: ['strong'] },
        ]);
    });

    it('renders a bold mention pill wrapped in <strong>', () => {
        const html = renderMessageBody(`**@[Alice](${alice.id})**`, [alice]);

        expect(html).toContain('<strong><span');
        expect(html).toContain('>@Alice<');
        expect(html).toContain('</span></strong>');
    });

    it('bolds a bare URL link', () => {
        const html = renderMessageBody('**https://example.com**');

        expect(html).toBe(
            '<strong><a href="https://example.com" target="_blank" rel="noopener noreferrer nofollow" class="text-primary underline underline-offset-2 hover:no-underline">https://example.com</a></strong>',
        );
    });
});

describe('inline code suppresses inner parsing', () => {
    it('does not resolve a mention inside inline code', () => {
        expect(
            tokenizeMessageBody(`\`@[Alice](${alice.id})\``, [alice]),
        ).toEqual([
            { kind: 'html', html: `<code>@[Alice](${alice.id})</code>` },
        ]);
    });

    it('does not autolink a URL inside inline code', () => {
        expect(tokenizeMessageBody('`see https://example.com`')).toEqual([
            {
                kind: 'html',
                html: '<code>see https://example.com</code>',
            },
        ]);
    });

    it('does not resolve an emoji shortcode inside inline code', () => {
        expect(tokenizeMessageBody('`:shipit:`', [], emojiMap)).toEqual([
            { kind: 'html', html: '<code>:shipit:</code>' },
        ]);
    });
});

describe('malformed markup degrades to literal text', () => {
    it('leaves an unbalanced ** as literal characters', () => {
        expect(renderMessageBody('a ** b')).toBe('a ** b');
    });

    it('leaves a lone backtick as a literal character', () => {
        expect(renderMessageBody('a `b')).toBe('a `b');
    });

    it('never uses [text] as the anchor label for a markdown-style link', () => {
        const html = renderMessageBody('[text](http://evil.test)');

        // The `[text](…)` label stays literal; only the bare URL inside is
        // autolinked, and its anchor text is the URL itself — never `text`.
        expect(html).toContain('[text](<a href="http://evil.test"');
        expect(html).toContain('>http://evil.test</a>)');
        expect(html).not.toContain('>text</a>');
    });
});

describe('DOMPurify sanitization boundary', () => {
    it('escapes an attacker-authored <img onerror> to inert text', () => {
        const html = renderMessageBody('<img src=x onerror=alert(1)>');

        // No live <img> tag survives; the payload is inert escaped text.
        expect(html).not.toContain('<img');
        expect(html).toBe('&lt;img src=x onerror=alert(1)&gt;');
    });

    it('escapes a <script> payload to inert text', () => {
        const html = renderMessageBody('<script>alert(1)</script>');

        expect(html).not.toContain('<script');
        expect(html).toBe('&lt;script&gt;alert(1)&lt;/script&gt;');
    });

    it('never emits a link (and no javascript: href) from [x](url)', () => {
        const html = renderMessageBody('**[x](javascript:alert(1))**');

        // The Markdown link rule is disabled, so this stays literal text
        // wrapped by the mark — no anchor tag, no live scheme, ever.
        expect(html).not.toContain('<a ');
        expect(html).not.toContain('href=');
        expect(html).toBe('<strong>[x](javascript:alert(1))</strong>');
    });

    it('escapes a quote in a bare URL so it cannot break out of the href', () => {
        const html = renderMessageBody(
            'see https://x.com/"onmouseover=alert(1)',
        );

        // A URL runs to the next whitespace, so it can carry a `"`. The quote is
        // escaped inside the attribute value — no live event handler is injected
        // onto the anchor, which is the guarantee on the DOM-less SSR path where
        // DOMPurify is skipped.
        expect(html).not.toMatch(/<a[^>]*\sonmouseover=/i);
        expect(html).toContain(
            'href="https://x.com/&quot;onmouseover=alert(1"',
        );
    });
});

describe('group mentions', () => {
    it('splits a resolved group mention out as its own segment', () => {
        expect(
            tokenizeMessageBody(
                `ping @[dev-team](group:${devs.id}) now`,
                [],
                {},
                [devs],
            ),
        ).toEqual([
            { kind: 'html', html: 'ping ' },
            { kind: 'groupMention', id: devs.id, name: 'dev-team' },
            { kind: 'html', html: ' now' },
        ]);
    });

    it('falls back to plain text for a group that no longer exists', () => {
        expect(
            tokenizeMessageBody(
                `ping @[dev-team](group:${devs.id})`,
                [],
                {},
                [],
            ),
        ).toEqual([
            { kind: 'html', html: 'ping ' },
            { kind: 'html', html: '@dev-team' },
        ]);
    });

    it('does not resolve a group id against the people mentioned', () => {
        expect(
            tokenizeMessageBody(
                `@[dev-team](group:${alice.id})`,
                [alice],
                {},
                [],
            ),
        ).toEqual([{ kind: 'html', html: '@dev-team' }]);
    });

    it('does not resolve a person id against the workspace groups', () => {
        expect(
            tokenizeMessageBody(`@[Alice](${devs.id})`, [], {}, [devs]),
        ).toEqual([{ kind: 'html', html: '@Alice' }]);
    });

    it('tokenizes a person and a group in the same run', () => {
        expect(
            tokenizeMessageBody(
                `@[Alice](${alice.id}) & @[dev-team](group:${devs.id})`,
                [alice],
                {},
                [devs],
            ),
        ).toEqual([
            { kind: 'mention', id: alice.id, name: 'Alice' },
            { kind: 'html', html: ' &amp; ' },
            { kind: 'groupMention', id: devs.id, name: 'dev-team' },
        ]);
    });

    it('does not resolve a group mention inside inline code', () => {
        expect(
            tokenizeMessageBody(`\`@[dev-team](group:${devs.id})\``, [], {}, [
                devs,
            ]),
        ).toEqual([
            {
                kind: 'html',
                html: `<code>@[dev-team](group:${devs.id})</code>`,
            },
        ]);
    });

    it('carries the surrounding marks onto the group segment', () => {
        expect(
            tokenizeMessageBody(`**@[dev-team](group:${devs.id})**`, [], {}, [
                devs,
            ]),
        ).toEqual([
            {
                kind: 'groupMention',
                id: devs.id,
                name: 'dev-team',
                marks: ['strong'],
            },
        ]);
    });

    it('renders a resolved group as a pill in its own hue', () => {
        const html = renderMessageBody(
            `@[dev-team](group:${devs.id})`,
            [],
            {},
            [devs],
        );

        expect(html).toContain('@dev-team');
        expect(html).toContain('text-violet-700');
    });

    it('renders a bold group pill wrapped in <strong>', () => {
        expect(
            renderMessageBody(`**@[dev-team](group:${devs.id})**`, [], {}, [
                devs,
            ]),
        ).toMatch(/^<strong><span[^>]*>@dev-team<\/span><\/strong>$/);
    });

    it('escapes a group label before putting it in the pill', () => {
        const html = renderMessageBody(
            `@[<img src=x>](group:${devs.id})`,
            [],
            {},
            [devs],
        );

        expect(html).not.toContain('<img');
    });
});

describe('messageBodyPreview', () => {
    it('strips inline mark syntax to clean text', () => {
        expect(messageBodyPreview('a **b** _c_ ~~d~~')).toBe('a b c d');
    });

    it('keeps inline code content as literal text', () => {
        expect(messageBodyPreview('run `npm test` now')).toBe(
            'run npm test now',
        );
    });

    it('still collapses a mention token to @Name', () => {
        expect(messageBodyPreview(`hi **@[Alice](${alice.id})**`)).toBe(
            'hi @Alice',
        );
    });

    it('collapses a group token to its handle', () => {
        expect(messageBodyPreview(`hi @[dev-team](group:${devs.id})`)).toBe(
            'hi @dev-team',
        );
    });
});

describe('messageBodyCopyText', () => {
    it('returns the body exactly as typed, newlines and mark syntax intact', () => {
        expect(messageBodyCopyText('a **b**\n_c_ and `code`')).toBe(
            'a **b**\n_c_ and `code`',
        );
    });

    it('collapses a mention token to the @Name the author typed', () => {
        expect(messageBodyCopyText(`hi @[Alice](${alice.id})`)).toBe(
            'hi @Alice',
        );
    });

    it('collapses a group token to its handle', () => {
        expect(messageBodyCopyText(`ping @[dev-team](group:${devs.id})`)).toBe(
            'ping @dev-team',
        );
    });
});
