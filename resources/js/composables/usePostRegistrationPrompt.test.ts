import { describe, expect, it, vi } from 'vitest';

const visit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', () => ({
    router: { delete: visit },
    usePage: () => ({ props: { postRegistrationPrompt: null } }),
}));

const { shouldPromptForPasskey } =
    await import('@/composables/usePostRegistrationPrompt');

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
