import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const repoRoot = fileURLToPath(new URL('../../..', import.meta.url));
const appCss = readFileSync(`${repoRoot}/resources/css/app.css`, 'utf8');
const viteConfig = readFileSync(`${repoRoot}/vite.config.ts`, 'utf8');

/**
 * Reads a single custom-property value from a specific selector block in app.css.
 * Guards the "The Desk" foundation contract: components consume these tokens, so a
 * regression in a value silently reskins the whole app.
 */
function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function tokenValue(selector: string, property: string): string | null {
    const block = appCss.match(
        new RegExp(`${escapeRegExp(selector)}\\s*\\{([\\s\\S]*?)\\}`),
    );

    if (block === null) {
        return null;
    }

    const declaration = block[1].match(
        new RegExp(`${escapeRegExp(property)}:\\s*([^;]+);`),
    );

    return declaration === null ? null : declaration[1].trim();
}

describe('"The Desk" design foundation', () => {
    describe('warm palette — light (:root)', () => {
        it.each([
            ['--background', '#e7e4dd'],
            ['--card', '#fefdfb'],
            ['--popover', '#fbfaf7'],
            ['--foreground', '#1d1a15'],
            ['--primary', '#1d1a15'],
            ['--primary-foreground', '#f3efe4'],
            // Darkened to clear WCAG AA on every surface (#269).
            ['--muted-foreground', '#6b6355'],
            ['--border', '#e3dfd5'],
        ])('maps %s to the warm value %s', (property, value) => {
            expect(tokenValue(':root', property)).toBe(value);
        });
    });

    describe('warm palette — dark (.dark)', () => {
        it.each([
            ['--background', '#12100c'],
            ['--card', '#1e1b15'],
            ['--foreground', '#f3efe4'],
            ['--primary', '#f3efe4'],
            // Lightened to clear WCAG AA on the dark muted surface (#269).
            ['--muted-foreground', '#9d947e'],
            ['--border', '#2e2a21'],
        ])('maps %s to the warm value %s', (property, value) => {
            expect(tokenValue('.dark', property)).toBe(value);
        });
    });

    describe('brass accent', () => {
        it('defines the brass accent and border in light', () => {
            expect(tokenValue(':root', '--brass')).toBe('#c9a35c');
            expect(tokenValue(':root', '--brass-border')).toBe('#b98e3f');
        });

        it('defines a muted brass reaction-pill fill and readable text in light', () => {
            expect(tokenValue(':root', '--brass-fill')).toBe(
                'rgba(201, 163, 92, 0.14)',
            );
            expect(tokenValue(':root', '--brass-fill-foreground')).toBe(
                '#6a521e',
            );
        });

        it('keeps brass at #c9a35c and lightens pill text in dark', () => {
            expect(tokenValue('.dark', '--brass')).toBe('#c9a35c');
            expect(tokenValue('.dark', '--brass-fill-foreground')).toBe(
                '#dcb56a',
            );
        });

        it('keeps solid-brass badge text ink in both themes', () => {
            expect(tokenValue(':root', '--brass-foreground')).toBe('#1d1a15');
        });

        it('exposes brass as Tailwind color utilities via @theme inline', () => {
            expect(appCss).toContain('--color-brass: var(--brass);');
            expect(appCss).toContain(
                '--color-brass-foreground: var(--brass-foreground);',
            );
            expect(appCss).toContain('--color-brass-fill: var(--brass-fill);');
            expect(appCss).toContain(
                '--color-brass-fill-foreground: var(--brass-fill-foreground);',
            );
        });
    });

    describe('Newsreader serif', () => {
        it('exposes a font-serif utility built on Newsreader', () => {
            const serif = tokenValue('@theme inline', '--font-serif');
            expect(serif).not.toBeNull();
            expect(serif).toContain('Newsreader');
        });

        it('self-hosts Newsreader through the Vite fonts pipeline (no external request)', () => {
            expect(viteConfig).toMatch(/bunny\(\s*'Newsreader'/);
        });

        it('ships Newsreader in the italic style used by mastheads', () => {
            const newsreader = viteConfig.match(
                /bunny\(\s*'Newsreader',\s*\{([\s\S]*?)\}\s*\)/,
            );
            expect(newsreader).not.toBeNull();
            expect(newsreader![1]).toContain("'italic'");
            expect(newsreader![1]).toMatch(/weights:\s*\[400,\s*500,\s*600\]/);
        });
    });
});
