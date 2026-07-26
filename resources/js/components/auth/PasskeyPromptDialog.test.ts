// @vitest-environment jsdom
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { App, Ref } from 'vue';
import { createApp, defineComponent, h, nextTick } from 'vue';

/**
 * Drives the prompt through its four states. The WebAuthn ceremony itself is the
 * vendor hook's, stubbed here because nothing in a test can produce a credential
 * — what these cover is everything around it: the prefill, the dismissal guard
 * while the browser's sheet is up, the retry after a cancelled ceremony, and the
 * handover to the first-run tour.
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
    supported: true,
}));

/** The shared props the layout would have handed down. */
const pageProps = vi.hoisted(() => ({
    currentDevice: { browser: 'Chrome', platform: 'macOS' },
    postRegistrationPrompt: 'passkey' as 'passkey' | null,
}));

const answered = vi.hoisted(() => vi.fn());

/** Closing the dialog from outside: Escape, a scrim click, or a swipe down. */
const dialog = vi.hoisted(() => ({ requestClose: () => {} }));

vi.mock('@inertiajs/vue3', () => ({
    router: { delete: answered },
    usePage: () => ({ props: pageProps }),
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

vi.mock('@lucide/vue', () => ({
    Check: { render: () => h('svg') },
    Lock: { render: () => h('svg') },
    CircleAlert: { render: () => h('svg') },
    Loader2Icon: { render: () => h('svg') },
}));

// The dialog primitives are stubbed to passthroughs so the tests read the
// prompt's own content and its answer to a close request, rather than Reka UI's
// teleport and focus machinery.
vi.mock('@/components/ui/dialog', async () => {
    const { defineComponent: define, h: create } = await import('vue');

    const passthrough = (name: string, tag = 'div') =>
        define({
            name,
            setup:
                (_props, { slots }) =>
                () =>
                    create(tag, { 'data-stub': name }, slots.default?.()),
        });

    return {
        Dialog: define({
            name: 'DialogStub',
            props: { open: { type: Boolean, default: false } },
            emits: ['update:open'],
            setup(props, { slots, emit }) {
                dialog.requestClose = () => emit('update:open', false);

                return () =>
                    props.open
                        ? create(
                              'div',
                              { 'data-test': 'dialog' },
                              slots.default?.(),
                          )
                        : null;
            },
        }),
        DialogContent: passthrough('DialogContent'),
        DialogTitle: passthrough('DialogTitle', 'h2'),
        DialogDescription: passthrough('DialogDescription', 'p'),
    };
});

let app: App | null = null;
const done = vi.fn();

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

async function mountPrompt(): Promise<HTMLElement> {
    const PasskeyPromptDialog = (await import('./PasskeyPromptDialog.vue'))
        .default;

    const host = document.createElement('div');
    document.body.appendChild(host);

    app = createApp(
        defineComponent({
            setup: () => () => h(PasskeyPromptDialog, { onDone: done }),
        }),
    );
    app.config.globalProperties.$t = translate;
    app.mount(host);

    await nextTick();

    return host;
}

function nameField(host: HTMLElement): HTMLInputElement {
    return host.querySelector<HTMLInputElement>(
        '[data-test="passkey-prompt-name"]',
    )!;
}

/** Type a name and hit the primary action. */
async function submit(host: HTMLElement, name?: string): Promise<void> {
    if (name !== undefined) {
        const field = nameField(host);

        field.value = name;
        field.dispatchEvent(new Event('input'));
        await nextTick();
    }

    host.querySelector<HTMLElement>('[data-test="prompt-primary"]')?.click();
    await nextTick();
    await nextTick();
}

beforeEach(() => {
    vendor.register.mockClear();
    vendor.register.mockImplementation(() => Promise.resolve());
    vendor.received = null;
    vendor.state = null;
    vendor.supported = true;
    pageProps.currentDevice = { browser: 'Chrome', platform: 'macOS' };
    pageProps.postRegistrationPrompt = 'passkey';
    answered.mockClear();
    done.mockClear();
    // jsdom ships no matchMedia; answer the app's one breakpoint query as desktop.
    window.matchMedia = ((query: string) => ({
        media: query,
        matches: false,
        addEventListener: () => {},
        removeEventListener: () => {},
    })) as unknown as typeof window.matchMedia;
});

afterEach(() => {
    app?.unmount();
    app = null;
    document.body.innerHTML = '';
    vi.useRealTimers();
});

it('offers the benefit, prefilled with the device this session is on', async () => {
    const host = await mountPrompt();

    expect(host.querySelector('h2')?.textContent).toContain(
        'One more thing: skip the password next time',
    );
    expect(nameField(host).value).toBe('Chrome on macOS');
    expect(host.textContent).toContain(
        "You can't rename it later, so pick something you'll recognise.",
    );
    expect(host.textContent).toContain(
        'You can add one later in Settings → Security.',
    );
});

it('enrols under the name in the field', async () => {
    await submit(await mountPrompt(), 'Work laptop');

    expect(vendor.register).toHaveBeenCalledWith('Work laptop');
});

it('refuses every way out while the ceremony is in flight', async () => {
    // A ceremony that never resolves leaves the prompt in its waiting state, with
    // the browser's own sheet notionally on top of it.
    vendor.register.mockImplementation(() => {
        vendor.state!.isLoading.value = true;

        return new Promise<void>(() => {});
    });

    const host = await mountPrompt();

    await submit(host, 'Work laptop');

    expect(
        host.querySelector('[data-test="passkey-prompt-waiting"]')?.textContent,
    ).toContain('Waiting for your device…');
    expect(host.querySelector('[data-test="passkey-prompt-form"]')).toBeNull();
    expect(
        host
            .querySelector('[data-test="prompt-primary"]')
            ?.hasAttribute('disabled'),
    ).toBe(true);

    dialog.requestClose();
    await nextTick();

    // Closing underneath the ceremony would strand it, so the dialog stays.
    expect(host.querySelector('[data-test="dialog"]')).not.toBeNull();
    expect(answered).not.toHaveBeenCalled();
    expect(done).not.toHaveBeenCalled();
});

it('can be dismissed from the scrim in every other state', async () => {
    const host = await mountPrompt();

    dialog.requestClose();
    await nextTick();

    expect(host.querySelector('[data-test="dialog"]')).toBeNull();
    // Answered server-side, so a refresh never re-asks, and the tour follows.
    expect(answered).toHaveBeenCalledOnce();
    expect(done).toHaveBeenCalledOnce();
});

it('hands over to the tour when "Not now" is taken', async () => {
    const host = await mountPrompt();

    host.querySelector<HTMLElement>('[data-test="prompt-secondary"]')?.click();
    await nextTick();

    expect(host.querySelector('[data-test="dialog"]')).toBeNull();
    expect(answered).toHaveBeenCalledOnce();
    expect(done).toHaveBeenCalledOnce();
});

it('keeps the typed name and offers a retry after a cancelled ceremony', async () => {
    vendor.register.mockImplementation(() => {
        vendor.state!.error.value = 'NotAllowedError';

        return Promise.resolve();
    });

    const host = await mountPrompt();

    await submit(host, 'Work laptop');

    expect(nameField(host).value).toBe('Work laptop');
    expect(nameField(host).getAttribute('aria-invalid')).toBe('true');
    expect(host.textContent).toContain(
        "That didn't finish. Your device cancelled or timed out, so try again.",
    );
    expect(
        host.querySelector('[data-test="prompt-primary"]')?.textContent,
    ).toContain('Try again');
    // A failure is not an answer: nothing is cleared and the tour still waits.
    expect(answered).not.toHaveBeenCalled();
    expect(done).not.toHaveBeenCalled();
});

it('names the field itself rather than the ceremony when the name is blank', async () => {
    const host = await mountPrompt();

    await submit(host, '   ');

    expect(vendor.register).not.toHaveBeenCalled();
    expect(host.textContent).toContain(
        'Give this passkey a name so you can recognise it later.',
    );
    expect(
        host.querySelector('[data-test="prompt-primary"]')?.textContent,
    ).toContain('Create passkey');
});

it('confirms the saved passkey, holds it for a beat, then hands over', async () => {
    vi.useFakeTimers();
    // Mirrors the vendor's own order: success is reported from inside the ceremony,
    // while it still counts as in flight, so the confirmation has to win over the
    // waiting state rather than flash behind it.
    vendor.register.mockImplementation(() => {
        vendor.state!.isLoading.value = true;
        vendor.received?.onSuccess?.();
        vendor.state!.isLoading.value = false;

        return Promise.resolve();
    });

    const host = await mountPrompt();

    await submit(host, 'Work laptop');

    expect(host.querySelector('h2')?.textContent).toContain(
        'Passkey saved: Work laptop',
    );
    // A finished prompt has no use for its actions or its field.
    expect(host.querySelector('[data-test="prompt-primary"]')).toBeNull();
    expect(host.querySelector('[data-test="passkey-prompt-form"]')).toBeNull();
    expect(done).not.toHaveBeenCalled();

    vi.advanceTimersByTime(2000);
    await nextTick();

    expect(host.querySelector('[data-test="dialog"]')).toBeNull();
    expect(answered).toHaveBeenCalledOnce();
    expect(done).toHaveBeenCalledOnce();
});

it('never opens on a browser without WebAuthn, and clears the queued prompt', async () => {
    vendor.supported = false;

    const host = await mountPrompt();

    // A modal offering something the browser cannot do is pure friction.
    expect(host.querySelector('[data-test="dialog"]')).toBeNull();
    expect(answered).toHaveBeenCalledOnce();
    expect(done).toHaveBeenCalledOnce();
});

it('shortens its copy for the bottom sheet', async () => {
    window.matchMedia = ((query: string) => ({
        media: query,
        matches: true,
        addEventListener: () => {},
        removeEventListener: () => {},
    })) as unknown as typeof window.matchMedia;

    const host = await mountPrompt();

    expect(host.querySelector('h2')?.textContent).toContain(
        'Skip the password next time',
    );
    expect(host.querySelector('h2')?.textContent).not.toContain(
        'One more thing',
    );
    expect(host.textContent).toContain('Sign in with Face ID');
});
