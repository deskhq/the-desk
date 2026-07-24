// @vitest-environment jsdom
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { App } from 'vue';
import { createApp, h, nextTick } from 'vue';

/**
 * Renders `<InstallAppDialog>` with the dialog primitives stubbed to plain
 * passthroughs, so the tests exercise the sheet's own content: which variant it
 * takes, what it claims, and what each control does. The install state comes
 * from the real `useAppInstall`, driven through a stubbed browser.
 */
const props = vi.hoisted(() => ({
    name: 'The Desk',
    currentTeam: { name: 'Acme Co' } as { name: string } | null,
    webPush: { enabled: true, publicKey: 'BKey' } as {
        enabled: boolean;
        publicKey: string | null;
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props }),
}));

vi.mock('@lucide/vue', () => ({
    Bell: { render: () => h('svg') },
    Check: { render: () => h('svg') },
    Download: { render: () => h('svg') },
    PanelTop: { render: () => h('svg') },
    Share: { render: () => h('svg') },
    SquarePlus: { render: () => h('svg') },
}));

vi.mock('@/components/ui/dialog', async () => {
    const { defineComponent, h: create } = await import('vue');

    const passthrough = (name: string, tag = 'div') =>
        defineComponent({
            name,
            setup:
                (_, { slots }) =>
                () =>
                    create(tag, { 'data-stub': name }, slots.default?.()),
        });

    return {
        Dialog: defineComponent({
            name: 'DialogStub',
            props: { open: { type: Boolean, default: false } },
            setup:
                (stubProps, { slots }) =>
                () =>
                    stubProps.open ? create('div', slots.default?.()) : null,
        }),
        DialogContent: passthrough('DialogContent'),
        DialogTitle: passthrough('DialogTitle', 'h2'),
        DialogDescription: passthrough('DialogDescription', 'p'),
    };
});

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

/** The two Safari agents that get a walkthrough rather than a prompt. */
const IPHONE_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari';
const MAC_SAFARI_AGENT =
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15';

/** Chrome on a phone: it prompts, but the app lands on the home screen. */
const ANDROID_AGENT =
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36';

/** Whether the stubbed browser is one that would fire `beforeinstallprompt`. */
function prompts(userAgent: string): boolean {
    return userAgent !== IPHONE_AGENT && userAgent !== MAC_SAFARI_AGENT;
}

function platformFor(userAgent: string): string {
    if (userAgent === IPHONE_AGENT) {
        return 'iPhone';
    }

    if (userAgent === ANDROID_AGENT) {
        return 'Linux armv8l';
    }

    return userAgent === MAC_SAFARI_AGENT ? 'MacIntel' : 'Linux x86_64';
}

function stubBrowser(userAgent: string): void {
    vi.stubGlobal('navigator', {
        userAgent,
        platform: platformFor(userAgent),
        maxTouchPoints: 0,
    });
    window.matchMedia = ((query: string) => ({
        media: query,
        matches: false,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
    })) as unknown as typeof window.matchMedia;
}

/** The stand-in for the event Chrome hands us, so `prompt()` can be observed. */
const prompt = vi.fn(() => Promise.resolve());

async function boot(
    userAgent = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/140',
    outcome: 'accepted' | 'dismissed' = 'accepted',
): Promise<void> {
    stubBrowser(userAgent);
    vi.resetModules();

    const { initializeAppInstall } =
        await import('@/composables/useAppInstall');

    initializeAppInstall();

    if (prompts(userAgent)) {
        window.dispatchEvent(
            Object.assign(
                new Event('beforeinstallprompt', { cancelable: true }),
                { prompt, userChoice: Promise.resolve({ outcome }) },
            ),
        );
    }
}

async function mountDialog(): Promise<HTMLElement> {
    const InstallAppDialog = (await import('./InstallAppDialog.vue')).default;

    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({
        render: () => h(InstallAppDialog, { open: true }),
    });
    app.config.globalProperties.$t = translate;
    app.mount(host);

    await nextTick();

    return host;
}

beforeEach(() => {
    window.localStorage.clear();
    window.sessionStorage.clear();
    prompt.mockClear();
    props.currentTeam = { name: 'Acme Co' };
    props.webPush = { enabled: true, publicKey: 'BKey' };
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

it('names the app and the origin it would install from', async () => {
    await boot();

    const host = await mountDialog();

    expect(host.querySelector('h2')?.textContent).toContain('Install The Desk');
    expect(host.querySelector('p')?.textContent).toContain(
        window.location.host,
    );
});

it('lists what installing buys, including the current workspace', async () => {
    await boot();

    const benefits = (await mountDialog()).querySelector(
        '[data-test="install-app-benefits"]',
    );

    expect(benefits?.textContent).toContain(
        'Opens in its own window, not a browser tab',
    );
    expect(benefits?.textContent).toContain('Keeps you signed in to Acme Co');
});

it('makes no push claim on the desktop, where push works in a tab', async () => {
    await boot();

    const benefits = (await mountDialog()).querySelector(
        '[data-test="install-app-benefits"]',
    );

    expect(benefits?.textContent).not.toContain('push');
});

it('promises the home screen on Android, and claims nothing about push', async () => {
    await boot(ANDROID_AGENT);

    const benefits = (await mountDialog()).querySelector(
        '[data-test="install-app-benefits"]',
    );

    expect(benefits?.textContent).toContain(
        'Opens full-screen from your home screen',
    );
    expect(benefits?.textContent).not.toContain('own window');
    expect(benefits?.textContent).not.toContain('push');
});

it('drops the workspace line when the page carries no team', async () => {
    props.currentTeam = null;
    await boot();

    const benefits = (await mountDialog()).querySelector(
        '[data-test="install-app-benefits"]',
    );

    expect(benefits?.textContent).not.toContain('Keeps you signed in to');
});

it('hands off to the browser prompt on confirm', async () => {
    await boot();

    const host = await mountDialog();

    host.querySelector<HTMLElement>(
        '[data-test="install-app-confirm"]',
    )?.click();
    await nextTick();

    expect(prompt).toHaveBeenCalledOnce();
});

it('records "Not now" so the invitation does not come back', async () => {
    await boot();

    const host = await mountDialog();

    host.querySelector<HTMLElement>(
        '[data-test="install-app-dismiss"]',
    )?.click();
    await nextTick();

    expect(window.localStorage.getItem('install.dismissedAt')).not.toBeNull();
    expect(prompt).not.toHaveBeenCalled();
});

it('walks iOS through the share sheet instead of offering a button', async () => {
    await boot(IPHONE_AGENT);

    const host = await mountDialog();
    const steps = host.querySelector('[data-test="install-app-steps"]');

    expect(host.querySelector('h2')?.textContent).toContain(
        'Add to Home Screen',
    );
    expect(host.querySelector('[data-test="install-app-confirm"]')).toBeNull();
    expect(steps?.textContent).toContain('Tap Share in the Safari toolbar');
    expect(steps?.textContent).toContain('Scroll to Add to Home Screen');
    expect(steps?.textContent).toContain('Tap Add — done');
    expect(
        host.querySelector('[data-test="install-app-push-note"]')?.textContent,
    ).toContain(
        'Notifications on iPhone only work once The Desk is on your home screen.',
    );
    expect(
        host.querySelector('[data-test="install-app-acknowledge"]')
            ?.textContent,
    ).toContain('Got it');
});

it('drops the iOS push note where the instance has no push', async () => {
    props.webPush = { enabled: false, publicKey: null };
    await boot(IPHONE_AGENT);

    const host = await mountDialog();

    expect(
        host.querySelector('[data-test="install-app-push-note"]'),
    ).toBeNull();
});

it('walks macOS Safari through Add to Dock instead of offering a button', async () => {
    await boot(MAC_SAFARI_AGENT);

    const host = await mountDialog();
    const steps = host.querySelector('[data-test="install-app-steps"]');

    expect(host.querySelector('h2')?.textContent).toContain('Add to Dock');
    expect(host.querySelector('p')?.textContent).toContain(
        'From the Safari menu bar',
    );
    expect(host.querySelector('[data-test="install-app-confirm"]')).toBeNull();
    expect(steps?.textContent).toContain('Open File in the Safari menu bar');
    expect(steps?.textContent).toContain('Choose Add to Dock');
    expect(steps?.textContent).toContain('Click Add to finish');
    expect(
        host.querySelector('[data-test="install-app-acknowledge"]')
            ?.textContent,
    ).toContain('Got it');
});

it('makes no push claim on macOS, where Safari pushes from a plain tab', async () => {
    await boot(MAC_SAFARI_AGENT);

    const host = await mountDialog();

    expect(
        host.querySelector('[data-test="install-app-push-note"]'),
    ).toBeNull();
});
