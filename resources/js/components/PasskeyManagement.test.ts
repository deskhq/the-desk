// @vitest-environment jsdom
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { App, Ref } from 'vue';
import { createApp, h, nextTick } from 'vue';

/**
 * Covers the settings panel through the shared enrolment composable it was
 * refactored onto: the ceremony still runs against the vendor hook under the
 * trimmed name, and a success still closes the form and reloads the list. Only
 * the vendor hook is stubbed — `usePasskeyEnrolment` itself is the real one, so
 * the two surfaces cannot drift apart unnoticed.
 */
type VendorOptions = { onSuccess?: () => void };

const vendor = vi.hoisted(() => ({
    register: vi.fn<(name: string) => Promise<void>>(() => Promise.resolve()),
    received: null as VendorOptions | null,
    state: null as {
        isLoading: Ref<boolean>;
        error: Ref<string | null>;
        isSupported: Ref<boolean>;
    } | null,
    /** Whether the stubbed browser reports WebAuthn support. */
    supported: true,
}));

const reload = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    router: { reload },
    usePage: () => ({ props: { demoMode: false } }),
}));

vi.mock('@laravel/passkeys/vue', async () => {
    const { ref } = await import('vue');

    return {
        usePasskeyRegister: (options: VendorOptions) => {
            vendor.received = options;
            vendor.state = {
                isLoading: ref(false),
                error: ref<string | null>(null),
                isSupported: ref(vendor.supported),
            };

            return { register: vendor.register, ...vendor.state };
        },
    };
});

let app: App | null = null;

async function mountPanel(): Promise<HTMLElement> {
    const PasskeyManagement = (await import('./PasskeyManagement.vue')).default;

    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp({ render: () => h(PasskeyManagement, { passkeys: [] }) });
    app.config.globalProperties.$t = (key: string) => key;
    app.mount(host);

    await nextTick();

    return host;
}

/** Reveal the name field, type into it, and submit the form. */
async function enrolAs(host: HTMLElement, name: string): Promise<void> {
    host.querySelector<HTMLElement>(
        '[data-test="add-passkey-button"]',
    )?.click();
    await nextTick();

    const field = host.querySelector<HTMLInputElement>('#passkey_name');

    field!.value = name;
    field!.dispatchEvent(new Event('input'));
    await nextTick();

    host.querySelector<HTMLFormElement>(
        '[data-test="add-passkey-form"]',
    )?.dispatchEvent(new Event('submit', { cancelable: true }));
    await nextTick();
}

beforeEach(() => {
    vendor.register.mockClear();
    vendor.received = null;
    vendor.state = null;
    vendor.supported = true;
    reload.mockClear();
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
});

it('runs the shared ceremony under the trimmed name', async () => {
    await enrolAs(await mountPanel(), '  MacBook Pro  ');

    expect(vendor.register).toHaveBeenCalledWith('MacBook Pro');
});

it('closes the form and reloads the list once a passkey is saved', async () => {
    const host = await mountPanel();

    await enrolAs(host, 'MacBook Pro');
    vendor.received?.onSuccess?.();
    await nextTick();

    expect(host.querySelector('[data-test="add-passkey-form"]')).toBeNull();
    expect(reload).toHaveBeenCalledWith({ only: ['passkeys'] });
});

it('refuses a blank name inline without raising the browser sheet', async () => {
    const host = await mountPanel();

    await enrolAs(host, '   ');

    expect(vendor.register).not.toHaveBeenCalled();
    expect(host.textContent).toContain(
        'Give this passkey a name so you can recognise it later.',
    );
});

it('offers nothing to a browser without WebAuthn', async () => {
    vendor.supported = false;

    const host = await mountPanel();

    expect(host.querySelector('[data-test="add-passkey-button"]')).toBeNull();
    expect(
        host.querySelector('[data-test="passkeys-unsupported"]'),
    ).not.toBeNull();
});
