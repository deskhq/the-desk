// @vitest-environment jsdom
// DOMPurify needs a DOM; jsdom gives this file a `window` so sanitization runs
// exactly as it does in the browser.
import { describe, expect, it } from 'vitest';
import {
    MESSAGE_BODY_SANITIZE_CONFIG,
    QR_CODE_SANITIZE_CONFIG,
    SANITIZE_CONFIGS,
    sanitizeHtml,
    SEARCH_SNIPPET_SANITIZE_CONFIG,
} from '@/lib/sanitizeHtml';

/**
 * A shortened but structurally faithful sample of what Fortify's
 * `twoFactorQrCodeSvg()` emits — every element and attribute the real output
 * carries is represented, so the allowlist is asserted against the actual shape
 * rather than an invented one.
 */
const QR_SVG =
    '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="192" height="192" viewBox="0 0 192 192">' +
    '<rect x="0" y="0" width="192" height="192" fill="#ffffff"/>' +
    '<g transform="scale(4.683)"><g transform="translate(0,0)">' +
    '<path fill-rule="evenodd" d="M8 0L8 1L9 1L9 0Z" fill="#2d3748"/>' +
    '</g></g></svg>';

/**
 * The same QR code as DOMPurify re-serializes it: identical elements and
 * attributes, with the self-closing `<rect/>`/`<path/>` written as explicit
 * open/close pairs. The distinction is textual only — both parse to the same DOM
 * and render the same code.
 */
const QR_SVG_REPARSED = QR_SVG.replace(
    /<(rect|path)([^>]*)\/>/g,
    '<$1$2></$1>',
);

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

    it('keeps every element and attribute a two-factor QR code carries', () => {
        expect(sanitizeHtml(QR_SVG, QR_CODE_SANITIZE_CONFIG)).toBe(
            QR_SVG_REPARSED,
        );
    });

    it('strips scripting smuggled into a QR code SVG', () => {
        expect(
            sanitizeHtml(
                '<svg viewBox="0 0 1 1"><script>alert(1)</script>' +
                    '<rect x="0" y="0" onload="alert(1)"/>' +
                    '<foreignObject><iframe src="https://evil.test"></iframe></foreignObject>' +
                    '</svg>',
                QR_CODE_SANITIZE_CONFIG,
            ),
        ).toBe('<svg viewBox="0 0 1 1"><rect x="0" y="0"></rect></svg>');
    });
});

describe('SANITIZE_CONFIGS', () => {
    it('exposes each allowlist under its variant name', () => {
        expect(SANITIZE_CONFIGS).toEqual({
            messageBody: MESSAGE_BODY_SANITIZE_CONFIG,
            searchSnippet: SEARCH_SNIPPET_SANITIZE_CONFIG,
            qrCode: QR_CODE_SANITIZE_CONFIG,
        });
    });
});
