import { afterEach, expect, it, vi } from 'vitest';
import { registerServiceWorker } from './serviceWorker';

function stubNavigator(register?: ReturnType<typeof vi.fn>) {
    vi.stubGlobal('navigator', register ? { serviceWorker: { register } } : {});
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

it('registers the root-scoped worker so it controls every route', () => {
    const register = vi.fn(() => Promise.resolve({}));

    stubNavigator(register);
    registerServiceWorker();

    expect(register).toHaveBeenCalledWith('/service-worker.js', { scope: '/' });
});

it('does nothing on browsers without service worker support', () => {
    stubNavigator();

    expect(() => registerServiceWorker()).not.toThrow();
});

it('warns instead of throwing when registration is rejected', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const failure = new Error('insecure context');

    stubNavigator(vi.fn(() => Promise.reject(failure)));
    registerServiceWorker();
    await vi.waitFor(() =>
        expect(warn).toHaveBeenCalledWith(
            'Service worker registration failed',
            failure,
        ),
    );
});
