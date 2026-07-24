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

function stubBrowser(userAgent: string): void {
    vi.stubGlobal('navigator', {
        userAgent,
        platform: userAgent.includes('iPhone') ? 'iPhone' : 'Linux x86_64',
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

    if (!userAgent.includes('iPhone')) {
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
        'Opens full-screen from your home screen',
    );
    expect(benefits?.textContent).toContain('Required for push notifications');
    expect(benefits?.textContent).toContain('Keeps you signed in to Acme Co');
});

it('drops the push claim on an instance with no push configured', async () => {
    props.webPush = { enabled: false, publicKey: null };
    await boot();

    const benefits = (await mountDialog()).querySelector(
        '[data-test="install-app-benefits"]',
    );

    expect(benefits?.textContent).not.toContain(
        'Required for push notifications',
    );
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
    await boot('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari');

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
    await boot('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari');

    const host = await mountDialog();

    expect(
        host.querySelector('[data-test="install-app-push-note"]'),
    ).toBeNull();
});
