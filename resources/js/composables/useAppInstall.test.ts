// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick } from 'vue';

import type * as AppInstall from './useAppInstall';

type InstallModule = typeof AppInstall;

let app: App | null = null;

/**
 * The composable keeps its capture state at module scope (one browser, one
 * install prompt), so every test needs a fresh copy of the module.
 */
async function loadModule(): Promise<InstallModule> {
    vi.resetModules();

    return import('./useAppInstall');
}

/**
 * Run a composable inside a real component so its `onMounted` hydration fires,
 * and hand back what it returned.
 */
function inComponent<T>(factory: () => T): T {
    let captured: T | undefined;

    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({
        setup() {
            captured = factory();

            return () => h('div');
        },
    });
    app.mount(host);

    return captured as T;
}

/** A stand-in for the event Chrome fires when the app is installable. */
function installPromptEvent(outcome: 'accepted' | 'dismissed' = 'accepted') {
    return Object.assign(
        new Event('beforeinstallprompt', { cancelable: true }),
        {
            prompt: vi.fn(() => Promise.resolve()),
            userChoice: Promise.resolve({ outcome }),
        },
    );
}

function stubBrowser({
    userAgent = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/140',
    standaloneDisplay = false,
    iosStandalone = undefined as boolean | undefined,
    maxTouchPoints = 0,
    platform = 'Linux x86_64',
} = {}): void {
    vi.stubGlobal('navigator', {
        userAgent,
        platform,
        maxTouchPoints,
        ...(iosStandalone === undefined ? {} : { standalone: iosStandalone }),
    });

    window.matchMedia = ((query: string) => ({
        media: query,
        matches: query.includes('standalone') && standaloneDisplay,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
    })) as unknown as typeof window.matchMedia;
}

beforeEach(() => {
    window.localStorage.clear();
    window.sessionStorage.clear();
    stubBrowser();
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('the install prompt capture', () => {
    it('offers no install surface until the browser reports installability', async () => {
        const { initializeAppInstall, useAppInstall } = await loadModule();

        initializeAppInstall();

        const install = inComponent(() => useAppInstall());
        await nextTick();

        expect(install.showRow.value).toBe(false);
        expect(install.showCard.value).toBe(false);
    });

    it('surfaces the install row once `beforeinstallprompt` has fired', async () => {
        const { initializeAppInstall, useAppInstall } = await loadModule();

        initializeAppInstall();
        window.dispatchEvent(installPromptEvent());

        const install = inComponent(() => useAppInstall());
        await nextTick();

        expect(install.showRow.value).toBe(true);
    });

    it('suppresses the browser mini-infobar so our own invitation is the only one', async () => {
        const { initializeAppInstall } = await loadModule();

        initializeAppInstall();

        const event = installPromptEvent();
        window.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
    });
});

describe('an app that is already installed', () => {
    it('offers nothing when launched in standalone display mode', async () => {
        stubBrowser({ standaloneDisplay: true });

        const { initializeAppInstall, useAppInstall } = await loadModule();

        initializeAppInstall();
        window.dispatchEvent(installPromptEvent());

        const install = inComponent(() => useAppInstall());
        await nextTick();

        expect(install.showRow.value).toBe(false);
        expect(install.showCard.value).toBe(false);
    });

    it('offers nothing when iOS reports the home-screen launcher', async () => {
        stubBrowser({
            userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari',
            iosStandalone: true,
        });

        const { initializeAppInstall, useAppInstall } = await loadModule();

        initializeAppInstall();

        const install = inComponent(() => useAppInstall());
        await nextTick();

        expect(install.showRow.value).toBe(false);
    });

    it('clears both surfaces the moment the install completes', async () => {
        const { initializeAppInstall, useAppInstall } = await loadModule();

        initializeAppInstall();
        window.dispatchEvent(installPromptEvent());

        const install = inComponent(() => useAppInstall());
        await nextTick();
        expect(install.showRow.value).toBe(true);

        window.dispatchEvent(new Event('appinstalled'));
        await nextTick();

        expect(install.showRow.value).toBe(false);
    });
});

describe('the sidebar invitation card', () => {
    /** Boot the app once, as a browser session would. */
    async function bootSession(): Promise<InstallModule> {
        const module = await loadModule();

        window.sessionStorage.clear();
        module.initializeAppInstall();
        window.dispatchEvent(installPromptEvent());

        return module;
    }

    it('stays out of the way for a first-time visitor', async () => {
        const { useAppInstall } = await bootSession();

        const install = inComponent(() => useAppInstall());
        await nextTick();

        expect(install.showRow.value).toBe(true);
        expect(install.showCard.value).toBe(false);
    });

    it('invites from the second session on', async () => {
        await bootSession();
        const { useAppInstall } = await bootSession();

        const install = inComponent(() => useAppInstall());
        await nextTick();

        expect(install.showCard.value).toBe(true);
    });

    it('counts one session however many times the app boots within it', async () => {
        const module = await loadModule();

        window.sessionStorage.clear();
        module.initializeAppInstall();
        // A same-tab navigation re-runs the bundle without a new session.
        const again = await loadModule();
        again.initializeAppInstall();
        window.dispatchEvent(installPromptEvent());

        const install = inComponent(() => again.useAppInstall());
        await nextTick();

        expect(install.showCard.value).toBe(false);
    });

    it('never returns once dismissed, and leaves the row in place', async () => {
        await bootSession();
        const first = await bootSession();

        const dismissing = inComponent(() => first.useAppInstall());
        await nextTick();
        dismissing.dismissCard();
        await nextTick();

        expect(dismissing.showCard.value).toBe(false);
        expect(dismissing.showRow.value).toBe(true);

        const { useAppInstall } = await bootSession();
        app?.unmount();

        const later = inComponent(() => useAppInstall());
        await nextTick();

        expect(later.showCard.value).toBe(false);
        expect(later.showRow.value).toBe(true);
    });

    it('survives a browser that refuses storage', async () => {
        const module = await loadModule();

        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('storage disabled');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('storage disabled');
        });

        expect(() => module.initializeAppInstall()).not.toThrow();
        window.dispatchEvent(installPromptEvent());

        const install = inComponent(() => module.useAppInstall());
        await nextTick();

        expect(() => install.dismissCard()).not.toThrow();
        expect(install.showCard.value).toBe(false);

        vi.restoreAllMocks();
    });
});

describe('triggering the install', () => {
    it('hands the deferred prompt to the browser', async () => {
        const { initializeAppInstall, useAppInstall } = await loadModule();

        initializeAppInstall();
        const event = installPromptEvent('accepted');
        window.dispatchEvent(event);

        const install = inComponent(() => useAppInstall());
        await nextTick();
        await install.promptInstall();

        expect(event.prompt).toHaveBeenCalledOnce();
    });

    it('treats a declined browser prompt as a dismissal, so it is never re-asked', async () => {
        const { initializeAppInstall, useAppInstall } = await loadModule();

        initializeAppInstall();
        window.dispatchEvent(installPromptEvent('dismissed'));

        const install = inComponent(() => useAppInstall());
        await nextTick();
        await install.promptInstall();

        expect(
            window.localStorage.getItem('install.dismissedAt'),
        ).not.toBeNull();
    });

    it('does nothing on the instructional variant, which has no prompt to give', async () => {
        stubBrowser({
            userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari',
        });

        const { initializeAppInstall, useAppInstall } = await loadModule();

        initializeAppInstall();

        const install = inComponent(() => useAppInstall());
        await nextTick();

        await expect(install.promptInstall()).resolves.toBeUndefined();
    });
});

describe('the NEW badge on the menu row', () => {
    it('shows on the first menu that carries the row, and never again', async () => {
        const { initializeAppInstall, useAppInstall, useInstallRowBadge } =
            await loadModule();

        initializeAppInstall();
        window.dispatchEvent(installPromptEvent());

        const first = inComponent(() => useInstallRowBadge());
        await nextTick();
        expect(first.value).toBe(true);

        app?.unmount();

        const second = inComponent(() => {
            useAppInstall();

            return useInstallRowBadge();
        });
        await nextTick();

        expect(second.value).toBe(false);
    });

    it('is not spent by a menu that carries no install row', async () => {
        stubBrowser({ userAgent: 'Mozilla/5.0 (X11; Linux x86_64) Firefox' });

        const { initializeAppInstall, useInstallRowBadge } = await loadModule();

        initializeAppInstall();

        inComponent(() => useInstallRowBadge());
        await nextTick();

        expect(window.localStorage.getItem('install.rowSeen')).toBeNull();
    });
});

describe('iOS, which never fires an install prompt', () => {
    it('offers the instructional variant in a browser tab', async () => {
        stubBrowser({
            userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari',
        });

        const { initializeAppInstall, useAppInstall } = await loadModule();

        initializeAppInstall();

        const install = inComponent(() => useAppInstall());
        await nextTick();

        expect(install.showRow.value).toBe(true);
        expect(install.isInstructional.value).toBe(true);
    });

    it('recognises an iPad, which reports itself as a Mac', async () => {
        stubBrowser({
            userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari',
            platform: 'MacIntel',
            maxTouchPoints: 5,
        });

        const { initializeAppInstall, useAppInstall } = await loadModule();

        initializeAppInstall();

        const install = inComponent(() => useAppInstall());
        await nextTick();

        expect(install.isInstructional.value).toBe(true);
    });

    it('leaves a desktop Mac alone', async () => {
        stubBrowser({
            userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari',
            platform: 'MacIntel',
            maxTouchPoints: 0,
        });

        const { initializeAppInstall, useAppInstall } = await loadModule();

        initializeAppInstall();

        const install = inComponent(() => useAppInstall());
        await nextTick();

        expect(install.showRow.value).toBe(false);
        expect(install.isInstructional.value).toBe(false);
    });

    it('offers nothing on a browser that neither prompts nor is iOS', async () => {
        stubBrowser({ userAgent: 'Mozilla/5.0 (X11; Linux x86_64) Firefox' });

        const { initializeAppInstall, useAppInstall } = await loadModule();

        initializeAppInstall();

        const install = inComponent(() => useAppInstall());
        await nextTick();

        expect(install.showRow.value).toBe(false);
    });
});
