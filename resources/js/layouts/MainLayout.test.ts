import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const repoRoot = fileURLToPath(new URL('../../..', import.meta.url));
const mainLayout = readFileSync(
    `${repoRoot}/resources/js/layouts/MainLayout.vue`,
    'utf8',
);
const threadPanel = readFileSync(
    `${repoRoot}/resources/js/components/ThreadPanel.vue`,
    'utf8',
);

/**
 * The mobile shell must size against the *dynamic* viewport (`dvh`), not the
 * small viewport (`svh`).
 *
 * On iOS WebKit browsers whose chrome sits at the bottom (Arc, in-app webviews),
 * an `svh`-tall shell extends behind that bar, pushing the composer — the shell's
 * bottom row — off-screen (#790). `dvh` tracks the live visible viewport, so the
 * shell's bottom always clears the bar. Chromium resolves `svh` and `dvh`
 * identically, so a browser test cannot catch a regression here; this pins the
 * CSS mechanism at the source instead.
 */
describe('mobile shell viewport sizing', () => {
    it('sizes the main pane against the dynamic viewport', () => {
        expect(mainLayout).toContain('h-[calc(100dvh-1rem)]');
        expect(mainLayout).toContain('md:h-[calc(100dvh-1.75rem)]');
    });

    it('keeps the demo-banner offset on the dynamic viewport too', () => {
        expect(mainLayout).toContain(
            'h-[calc(100dvh-1rem-var(--demo-banner-height))]',
        );
        expect(mainLayout).toContain(
            'md:h-[calc(100dvh-1.75rem-var(--demo-banner-height))]',
        );
    });

    it('never sizes a full-height surface against the small viewport', () => {
        // `svh` is the regression unit: it excludes retractable chrome but not a
        // persistent bottom bar, so the composer hides behind it on iOS.
        expect(mainLayout).not.toContain('100svh');
    });

    it('lets the mobile thread push inherit the shell height rather than re-deriving it', () => {
        // The thread panel fills the (dynamic-viewport) shell via `inset-0`, so it
        // must not pin its own viewport-unit height and drift from the shell.
        expect(threadPanel).toContain('max-md:absolute');
        expect(threadPanel).toContain('max-md:inset-0');
        expect(threadPanel).not.toContain('100svh');
        expect(threadPanel).not.toContain('100vh');
    });
});
