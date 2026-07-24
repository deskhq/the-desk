import { describe, expect, it } from 'vitest';
import { pwaManifest } from './manifest';

describe('pwaManifest', () => {
    it('describes a standalone app named after the instance', () => {
        const manifest = pwaManifest('Acme Chat');

        expect(manifest.name).toBe('Acme Chat');
        expect(manifest.short_name).toBe('Acme Chat');
        expect(manifest.display).toBe('standalone');
        expect(manifest.start_url).toBe('/');
        expect(manifest.scope).toBe('/');
        expect(manifest.theme_color).toMatch(/^#[0-9a-f]{6}$/);
        expect(manifest.background_color).toMatch(/^#[0-9a-f]{6}$/);
    });

    it('declares the icon sizes browsers require to offer installation', () => {
        const icons = pwaManifest('The Desk').icons ?? [];

        expect(icons.map((icon) => icon.sizes)).toContain('192x192');
        expect(icons.map((icon) => icon.sizes)).toContain('512x512');
        expect(
            icons.every(
                (icon) =>
                    icon.type === 'image/png' && icon.src.startsWith('/icons/'),
            ),
        ).toBe(true);
    });

    it('ships a maskable icon so adaptive launchers do not letterbox the mark', () => {
        const icons = pwaManifest('The Desk').icons ?? [];

        expect(
            icons.filter((icon) => icon.purpose === 'maskable'),
        ).toHaveLength(1);
    });
});
