// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { isStandaloneDisplay } from './displayMode';

function stubBrowser({
    displayMode = false,
    iosStandalone = undefined as boolean | undefined,
} = {}): void {
    vi.stubGlobal('navigator', {
        ...(iosStandalone === undefined ? {} : { standalone: iosStandalone }),
    });

    window.matchMedia = ((query: string) => ({
        media: query,
        matches: query.includes('standalone') && displayMode,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
    })) as unknown as typeof window.matchMedia;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('the installed-app check', () => {
    it('recognises an app launched in standalone display mode', () => {
        stubBrowser({ displayMode: true });

        expect(isStandaloneDisplay()).toBe(true);
    });

    it('recognises an iOS home-screen launch, which sets no display mode', () => {
        stubBrowser({ iosStandalone: true });

        expect(isStandaloneDisplay()).toBe(true);
    });

    it('reports a browser tab as what it is', () => {
        stubBrowser({ iosStandalone: false });

        expect(isStandaloneDisplay()).toBe(false);
    });
});
