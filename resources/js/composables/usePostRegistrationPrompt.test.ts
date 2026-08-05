import { describe, expect, it, vi } from 'vitest';

const visit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    router: { delete: visit },
    usePage: () => ({ props: { postRegistrationPrompt: null } }),
}));

const { shouldPromptForPasskey, usePostRegistrationPrompt } =
    await import('@/composables/usePostRegistrationPrompt');

describe('answering the prompt', () => {
    it('names the prop it invalidates, since the empty answer is cached', () => {
        // The prompt is a once prop: without naming it, the reply would be
        // subject to the exclusion and the client would keep restoring the
        // prompt it just answered (#1251).
        usePostRegistrationPrompt().answer();

        expect(visit).toHaveBeenCalledWith(
            expect.any(String),
            expect.objectContaining({ only: ['postRegistrationPrompt'] }),
        );
    });
});

describe('shouldPromptForPasskey', () => {
    it('prompts when the session queued the passkey prompt', () => {
        expect(shouldPromptForPasskey('passkey', true)).toBe(true);
    });

    it('does not prompt when nothing is queued for this session', () => {
        expect(shouldPromptForPasskey(null, true)).toBe(false);
    });

    it('does not prompt a browser that cannot do WebAuthn', () => {
        // A modal offering something the browser cannot do is pure friction, so
        // the prompt is skipped rather than shown and failed.
        expect(shouldPromptForPasskey('passkey', false)).toBe(false);
    });
});
