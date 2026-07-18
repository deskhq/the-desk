import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * Guards the theme tokens against WCAG 2.1 AA regressions (#269).
 *
 * The source of truth is the shipped stylesheet: we parse the real hex values
 * out of `resources/css/app.css` and assert independently-computed contrast
 * ratios against the WCAG constants (4.5:1 for body text, 3:1 for focus
 * indicators). This catches the token pairs that a rendered-DOM axe audit can't
 * reach on a natural page — the focus ring and the reaction-pill fill — as well
 * as `--muted-foreground`, which drives secondary text app-wide.
 */

const AA_TEXT = 4.5;
/** focus indicators / non-text (WCAG 1.4.11, 2.4.7) */
const AA_NON_TEXT = 3;

const cssPath = fileURLToPath(new URL('../../css/app.css', import.meta.url));
const css = readFileSync(cssPath, 'utf8');

type Rgb = [number, number, number];

function hexToRgb(hex: string): Rgb {
    const h = hex.replace('#', '');

    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)) as Rgb;
}

/** Composite a translucent foreground over an opaque background. */
function flatten(fg: Rgb, alpha: number, bg: Rgb): Rgb {
    return fg.map((c, i) => c * alpha + bg[i] * (1 - alpha)) as Rgb;
}

function relativeLuminance([r, g, b]: Rgb): number {
    const channel = (v: number): number => {
        const s = v / 255;

        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

function contrast(a: Rgb, b: Rgb): number {
    const la = relativeLuminance(a);
    const lb = relativeLuminance(b);
    const [hi, lo] = la > lb ? [la, lb] : [lb, la];

    return (hi + 0.05) / (lo + 0.05);
}

/**
 * Extract the `--token: #hex;` declarations from a single rule block, keyed by
 * token name (without the leading `--`).
 */
function tokensFromBlock(selector: string): Record<string, string> {
    const block = new RegExp(`${selector}\\s*\\{([^}]*)\\}`).exec(css);

    expect(block, `expected a "${selector}" block in app.css`).not.toBeNull();

    const tokens: Record<string, string> = {};

    for (const [, name, value] of block![1].matchAll(
        /--([\w-]+):\s*([^;]+);/g,
    )) {
        tokens[name] = value.trim();
    }

    return tokens;
}

const light = tokensFromBlock(':root');
const dark = tokensFromBlock('\\.dark');

/** Alpha of the translucent `--brass-fill` (`rgba(r, g, b, A)`). */
function fillAlpha(tokens: Record<string, string>): number {
    const alpha = /rgba\([^)]*,\s*([\d.]+)\)/.exec(tokens['brass-fill']);

    expect(alpha, 'expected --brass-fill to be an rgba() value').not.toBeNull();

    return Number(alpha![1]);
}

describe('theme contrast (WCAG AA)', () => {
    // --muted-foreground styles timestamps, placeholders, and secondary labels
    // painted directly on these surfaces, so it must clear body-text AA on each.
    const mutedSurfaces = [
        'background',
        'card',
        'muted',
        'secondary',
        'popover',
        'sidebar-background',
    ] as const;

    it.each(mutedSurfaces)(
        'light --muted-foreground meets AA on --%s',
        (surface) => {
            const ratio = contrast(
                hexToRgb(light['muted-foreground']),
                hexToRgb(light[surface]),
            );

            expect(ratio).toBeGreaterThanOrEqual(AA_TEXT);
        },
    );

    it.each(mutedSurfaces)(
        'dark --muted-foreground meets AA on --%s',
        (surface) => {
            const ratio = contrast(
                hexToRgb(dark['muted-foreground']),
                hexToRgb(dark[surface]),
            );

            expect(ratio).toBeGreaterThanOrEqual(AA_TEXT);
        },
    );

    // The focus indicator (shadcn `focus-visible:border-ring`, full opacity) has
    // to clear the 3:1 non-text threshold against the surfaces it frames.
    it('light --ring meets the 3:1 focus-indicator threshold on --background', () => {
        expect(
            contrast(hexToRgb(light['ring']), hexToRgb(light['background'])),
        ).toBeGreaterThanOrEqual(AA_NON_TEXT);
    });

    it('light --sidebar-ring meets the 3:1 focus-indicator threshold on the sidebar', () => {
        expect(
            contrast(
                hexToRgb(light['sidebar-ring']),
                hexToRgb(light['sidebar-background']),
            ),
        ).toBeGreaterThanOrEqual(AA_NON_TEXT);
    });

    // Reaction-pill text sits on the translucent brass fill composited over the
    // page surface; it must clear body-text AA in both themes.
    it('light --brass-fill-foreground meets AA on the reaction pill', () => {
        const pill = flatten(
            hexToRgb(light['brass'] ?? light['ring']),
            fillAlpha(light),
            hexToRgb(light['background']),
        );

        expect(
            contrast(hexToRgb(light['brass-fill-foreground']), pill),
        ).toBeGreaterThanOrEqual(AA_TEXT);
    });

    it('dark --brass-fill-foreground meets AA on the reaction pill', () => {
        const pill = flatten(
            hexToRgb(dark['brass'] ?? light['brass']),
            fillAlpha(dark),
            hexToRgb(dark['background']),
        );

        expect(
            contrast(hexToRgb(dark['brass-fill-foreground']), pill),
        ).toBeGreaterThanOrEqual(AA_TEXT);
    });

    // The unread "new" divider text (MessageList) also paints --brass-fill-foreground,
    // but as opaque text straight on the channel surface — `--card` on desktop,
    // `--background` on the mobile full-bleed pane. It replaced --brass-border,
    // which measured 2.94:1 (#278); lock the surfaces it renders on so the brass
    // debt can't creep back. The channel axe audit can't guard it: to stay scoped
    // to #268 it seeds a *read* message, so the divider never renders there.
    // The "Version X.Y.Z available" badge (settings/About.vue, `isBehind`) paints
    // --brass-foreground on a `bg-brass/10` fill composited over the settings
    // card. Dark mode overrode --brass but not --brass-foreground, leaving
    // near-black text on the dark fill (#518); lock body-text AA in both themes.
    const BADGE_FILL_ALPHA = 0.1; // matches the `bg-brass/10` utility

    it('light --brass-foreground meets AA on the version badge', () => {
        const badge = flatten(
            hexToRgb(light['brass']),
            BADGE_FILL_ALPHA,
            hexToRgb(light['card']),
        );

        expect(
            contrast(hexToRgb(light['brass-foreground']), badge),
        ).toBeGreaterThanOrEqual(AA_TEXT);
    });

    it('dark --brass-foreground meets AA on the version badge', () => {
        const badge = flatten(
            hexToRgb(dark['brass']),
            BADGE_FILL_ALPHA,
            hexToRgb(dark['card']),
        );

        expect(
            contrast(hexToRgb(dark['brass-foreground']), badge),
        ).toBeGreaterThanOrEqual(AA_TEXT);
    });

    const dividerSurfaces = ['card', 'background'] as const;

    it.each(dividerSurfaces)(
        'light unread-divider text (--brass-fill-foreground) meets AA on --%s',
        (surface) => {
            expect(
                contrast(
                    hexToRgb(light['brass-fill-foreground']),
                    hexToRgb(light[surface]),
                ),
            ).toBeGreaterThanOrEqual(AA_TEXT);
        },
    );

    it.each(dividerSurfaces)(
        'dark unread-divider text (--brass-fill-foreground) meets AA on --%s',
        (surface) => {
            expect(
                contrast(
                    hexToRgb(dark['brass-fill-foreground']),
                    hexToRgb(dark[surface]),
                ),
            ).toBeGreaterThanOrEqual(AA_TEXT);
        },
    );
});
