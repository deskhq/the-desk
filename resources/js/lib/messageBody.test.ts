import { describe, expect, it } from 'vitest';
import { renderMessageBody, tokenizeMessageBody } from '@/lib/messageBody';
import type { Mention } from '@/types';

const alice: Mention = {
    id: 'a1b2c3d4-e5f6-7890-1234-567890abcdef',
    name: 'Alice',
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
});
