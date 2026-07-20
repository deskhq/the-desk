// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { buildClientCookie, writeClientCookie } from '@/lib/cookies';

describe('buildClientCookie', () => {
    it('pins the cookie to the site root and a lifetime', () => {
        expect(buildClientCookie('appearance', 'dark', 3600, false)).toBe(
            'appearance=dark; path=/; max-age=3600; SameSite=Lax',
        );
    });

    it('marks the cookie Secure when the page is served over HTTPS', () => {
        expect(buildClientCookie('sidebar_state', 'false', 60, true)).toBe(
            'sidebar_state=false; path=/; max-age=60; SameSite=Lax; Secure',
        );
    });

    it('encodes a value that would otherwise break the cookie string', () => {
        expect(buildClientCookie('appearance', 'a b;c', 60, false)).toBe(
            'appearance=a%20b%3Bc; path=/; max-age=60; SameSite=Lax',
        );
    });
});

describe('writeClientCookie', () => {
    it('persists the cookie on the document', () => {
        writeClientCookie('appearance', 'dark', 60);

        expect(document.cookie).toContain('appearance=dark');
    });
});
