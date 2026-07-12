import { describe, expect, it } from 'vitest';
import type { CustomEmojiMap } from '@/lib/customEmoji';
import { renderMessageBody, tokenizeMessageBody } from '@/lib/messageBody';
import type { Mention } from '@/types';

const alice: Mention = {
    id: 'a1b2c3d4-e5f6-7890-1234-567890abcdef',
    name: 'Alice',
};

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
