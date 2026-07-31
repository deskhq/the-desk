// @vitest-environment jsdom
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick } from 'vue';

/**
 * Drives the real `useAppInstall` through a stubbed browser, so what the card
 * renders is decided by the same `beforeinstallprompt`/storage rules the app
 * ships — only Inertia's page props and the icon set are stood in for.
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { name: 'The Desk' } }),
}));

vi.mock('@lucide/vue', () => ({
    Download: { render: () => h('svg') },
    X: { render: () => h('svg') },
}));

let app: App | null = null;

function translate(
    key: string,
    replacements?: Record<string, unknown>,
): string {
    if (replacements === undefined) {
        return key;
    }

    return Object.entries(replacements).reduce(
        (line, [token, value]) => line.replaceAll(`:${token}`, String(value)),
        key,
    );
}

/** Chrome on a phone: it prompts, but the app lands on the home screen. */
const ANDROID_AGENT =
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36';

/** Boot the app as a returning visitor whose browser offers an install. */
async function bootInstallableSession(): Promise<void> {
    vi.resetModules();

    const { initializeAppInstall } =
        await import('@/composables/useAppInstall');

    // The card waits for a second session, so the first one is spent up front.
    window.sessionStorage.clear();
    initializeAppInstall();
    window.sessionStorage.clear();
    initializeAppInstall();

    window.dispatchEvent(
        Object.assign(new Event('beforeinstallprompt', { cancelable: true }), {
            prompt: vi.fn(() => Promise.resolve()),
            userChoice: Promise.resolve({ outcome: 'accepted' as const }),
        }),
    );
}

/** Let the leave transition run to completion before reading the DOM. */
async function flushTransition(): Promise<void> {
    await nextTick();
    await new Promise((resolve) => setTimeout(resolve, 50));
    await nextTick();
}

async function mountCard(): Promise<HTMLElement> {
    const InstallAppCard = (await import('./InstallAppCard.vue')).default;

    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({ render: () => h(InstallAppCard) });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    await nextTick();

    return host;
}

beforeEach(() => {
    window.localStorage.clear();
    window.sessionStorage.clear();
    vi.stubGlobal('navigator', {
        userAgent: 'Mozilla/5.0 (X11; Linux x86_64) Chrome/140',
        platform: 'Linux x86_64',
        maxTouchPoints: 0,
    });
    window.matchMedia = ((query: string) => ({
        media: query,
        matches: false,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
    })) as unknown as typeof window.matchMedia;
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

it('stays hidden on a browser that reports no install', async () => {
    vi.resetModules();
    const { initializeAppInstall } =
        await import('@/composables/useAppInstall');
    initializeAppInstall();

    const host = await mountCard();

    expect(host.querySelector('[data-test="install-app-card"]')).toBeNull();
});

it('invites the returning visitor by name, with both ways out', async () => {
    await bootInstallableSession();

    const card = (await mountCard()).querySelector(
        '[data-test="install-app-card"]',
    );

    expect(card).not.toBeNull();
    expect(card?.textContent).toContain('Keep The Desk close');
    expect(card?.textContent).toContain(
        'Install it as an app — own window, dock icon, no tab to lose.',
    );
    expect(
        card?.querySelector('[data-test="install-app-card-install"]')
            ?.textContent,
    ).toContain('Install app');
    expect(
        card?.querySelector('[data-test="install-app-card-not-now"]')
            ?.textContent,
    ).toContain('Not now');
    expect(
        card
            ?.querySelector('[data-test="install-app-card-dismiss"]')
            ?.getAttribute('aria-label'),
    ).toBe('Dismiss install invitation');
});

it('promises the home screen rather than a window on a phone', async () => {
    vi.stubGlobal('navigator', {
        userAgent: ANDROID_AGENT,
        platform: 'Linux armv8l',
        maxTouchPoints: 5,
    });
    await bootInstallableSession();

    const card = (await mountCard()).querySelector(
        '[data-test="install-app-card"]',
    );

    expect(card?.textContent).toContain(
        'Add it to your home screen for a full-screen app, one tap away.',
    );
    expect(card?.textContent).not.toContain('dock icon');
});

it('retires for good when "Not now" is taken', async () => {
    await bootInstallableSession();

    const host = await mountCard();

    host.querySelector<HTMLElement>(
        '[data-test="install-app-card-not-now"]',
    )?.click();
    await flushTransition();

    expect(host.querySelector('[data-test="install-app-card"]')).toBeNull();
    expect(window.localStorage.getItem('install.dismissedAt')).not.toBeNull();
});

it('retires for good when the ✕ is taken', async () => {
    await bootInstallableSession();

    const host = await mountCard();

    host.querySelector<HTMLElement>(
        '[data-test="install-app-card-dismiss"]',
    )?.click();
    await flushTransition();

    expect(host.querySelector('[data-test="install-app-card"]')).toBeNull();
    expect(window.localStorage.getItem('install.dismissedAt')).not.toBeNull();
});

it('opens the install sheet rather than prompting straight from the card', async () => {
    await bootInstallableSession();

    const { useDialog } = await import('@/composables/useDialog');
    const { isOpen } = useDialog('install');
    const host = await mountCard();

    expect(isOpen.value).toBe(false);

    host.querySelector<HTMLElement>(
        '[data-test="install-app-card-install"]',
    )?.click();
    await nextTick();

    expect(isOpen.value).toBe(true);
    isOpen.value = false;
});
