// @vitest-environment jsdom
// DOMPurify needs a DOM; jsdom gives this file a `window` so sanitization runs
// exactly as it does in the browser.
import { describe, expect, it } from 'vitest';
import {
    MESSAGE_BODY_SANITIZE_CONFIG,
    sanitizeHtml,
    SEARCH_SNIPPET_SANITIZE_CONFIG,
} from '@/lib/sanitizeHtml';

describe('sanitizeHtml', () => {
    it('keeps the tags and attributes on the allowlist', () => {
        expect(
            sanitizeHtml(
                '<strong>bold</strong> <a href="https://example.test" rel="noopener">link</a>',
                MESSAGE_BODY_SANITIZE_CONFIG,
            ),
        ).toBe(
            '<strong>bold</strong> <a href="https://example.test" rel="noopener">link</a>',
        );
    });

    it('strips a tag that is off the allowlist but keeps its text', () => {
        expect(
            sanitizeHtml(
                '<script>alert(1)</script><em>safe</em>',
                MESSAGE_BODY_SANITIZE_CONFIG,
            ),
        ).toBe('<em>safe</em>');
    });

    it('strips an attribute that is off the allowlist', () => {
        expect(
            sanitizeHtml(
                '<span onclick="alert(1)">hi</span>',
                MESSAGE_BODY_SANITIZE_CONFIG,
            ),
        ).toBe('<span>hi</span>');
    });

    it('strips a javascript: href', () => {
        expect(
            sanitizeHtml(
                '<a href="javascript:alert(1)">x</a>',
                MESSAGE_BODY_SANITIZE_CONFIG,
            ),
        ).toBe('<a>x</a>');
    });

    it('keeps search snippet highlights', () => {
        expect(
            sanitizeHtml(
                'a <mark>hit</mark> here',
                SEARCH_SNIPPET_SANITIZE_CONFIG,
            ),
        ).toBe('a <mark>hit</mark> here');
    });

    it('strips markup a snippet should never carry', () => {
        expect(
            sanitizeHtml(
                '<img src=x onerror="alert(1)"><mark>hit</mark>',
                SEARCH_SNIPPET_SANITIZE_CONFIG,
            ),
        ).toBe('<mark>hit</mark>');
    });
});
