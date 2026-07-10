import { describe, expect, it } from 'vitest';
import { previewHost } from '@/lib/linkPreview';

describe('previewHost', () => {
    it('returns the hostname of a URL', () => {
        expect(previewHost('https://example.com/some/path?q=1')).toBe(
            'example.com',
        );
    });

    it('strips a leading www.', () => {
        expect(previewHost('https://www.example.com')).toBe('example.com');
    });

    it('keeps a non-www subdomain', () => {
        expect(previewHost('https://docs.example.com/x')).toBe(
            'docs.example.com',
        );
    });

    it('falls back to the raw string for an unparseable URL', () => {
        expect(previewHost('not a url')).toBe('not a url');
    });
});
