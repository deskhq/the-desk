import { beforeEach, expect, it, vi } from 'vitest';
import type { Ref } from 'vue';

/**
 * The vendor hook stands in for the WebAuthn ceremony: the tests drive its refs
 * and callbacks directly, since nothing here can produce a real credential. What
 * they cover is our own wrapper — the route wiring, the blank-name guard, and the
 * enrolled name handed back to the caller.
 */
type VendorOptions = {
    routes?: { options?: string; submit?: string };
    onSuccess?: () => void;
};

const vendor = vi.hoisted(() => ({
    register: vi.fn<(name: string) => Promise<void>>(() => Promise.resolve()),
    /** The options the composable passed to the vendor hook. */
    received: null as VendorOptions | null,
    /** The state refs the vendor handed back on the latest call. */
    state: null as {
        isLoading: Ref<boolean>;
        error: Ref<string | null>;
        isSupported: Ref<boolean>;
    } | null,
}));

vi.mock('@laravel/passkeys/vue', async () => {
    const { ref } = await import('vue');

    return {
        usePasskeyRegister: (options: VendorOptions) => {
            vendor.received = options;
            vendor.state = {
                isLoading: ref(false),
                error: ref<string | null>(null),
                isSupported: ref(true),
            };

            return { register: vendor.register, ...vendor.state };
        },
    };
});

vi.mock('@/routes/passkey', () => ({
    registrationOptions: () => ({ url: '/user/passkeys/options' }),
    store: () => ({ url: '/user/passkeys' }),
}));

const { usePasskeyEnrolment } =
    await import('@/composables/usePasskeyEnrolment');

beforeEach(() => {
    vendor.register.mockClear();
    vendor.received = null;
    vendor.state = null;
});

it('runs the ceremony against the Fortify passkey endpoints', () => {
    usePasskeyEnrolment();

    expect(vendor.received?.routes).toEqual({
        options: '/user/passkeys/options',
        submit: '/user/passkeys',
    });
});

it('enrols under the trimmed name', async () => {
    const { enrol } = usePasskeyEnrolment();

    await enrol('  Chrome on macOS  ');

    expect(vendor.register).toHaveBeenCalledWith('Chrome on macOS');
});

it('refuses a blank name without starting a ceremony', async () => {
    const { enrol, error } = usePasskeyEnrolment();

    await enrol('   ');

    expect(vendor.register).not.toHaveBeenCalled();
    expect(error.value).toBe(
        'Give this passkey a name so you can recognise it later.',
    );
});

it('clears a stale error when a fresh attempt is made', async () => {
    const { enrol, error } = usePasskeyEnrolment();

    await enrol('');
    expect(error.value).not.toBeNull();

    await enrol('YubiKey');

    expect(error.value).toBeNull();
    expect(vendor.register).toHaveBeenCalledWith('YubiKey');
});

it('names the saved passkey when the ceremony succeeds', async () => {
    const enrolled = vi.fn();
    const { enrol } = usePasskeyEnrolment(enrolled);

    await enrol('  YubiKey  ');
    // The vendor hook's own success callback carries no payload, so the wrapper
    // is what remembers which name the ceremony ran under.
    vendor.received?.onSuccess?.();

    expect(enrolled).toHaveBeenCalledWith('YubiKey');
});

it('does not require a success callback', async () => {
    const { enrol } = usePasskeyEnrolment();

    await enrol('YubiKey');

    expect(() => vendor.received?.onSuccess?.()).not.toThrow();
});

it('passes the vendor loading, error, and support state straight through', () => {
    const { isLoading, error, isSupported } = usePasskeyEnrolment();

    vendor.state!.isLoading.value = true;
    vendor.state!.error.value = 'Ceremony cancelled.';
    vendor.state!.isSupported.value = false;

    expect(isLoading.value).toBe(true);
    expect(error.value).toBe('Ceremony cancelled.');
    expect(isSupported.value).toBe(false);
});
