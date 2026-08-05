import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { destroy as answerPrompt } from '@/actions/App/Http/Controllers/PostRegistrationPromptController';
import { POST_REGISTRATION_PROMPT_PROPS } from '@/lib/reloadProps';

/**
 * Whether the passkey enrolment prompt should be presented on this landing.
 *
 * Pure, so the gating is unit-testable without a DOM — the counterpart to
 * `shouldAutoStartTour`. Two conditions, both necessary: the session queued this
 * prompt (i.e. the account was created in it, and the feature is still on offer),
 * and the browser can actually run a WebAuthn ceremony.
 */
export function shouldPromptForPasskey(
    prompt: App.Enums.PostRegistrationPrompt | null,
    isSupported: boolean,
): boolean {
    return prompt === 'passkey' && isSupported;
}

/**
 * The one-time security prompt owed to an account created in this session, and
 * the way to answer it.
 *
 * The queued prompt is a shared Inertia prop rather than component state, so it
 * survives a refresh before acting; answering it clears the session key server
 * side, which is what stops a refresh re-asking.
 */
export function usePostRegistrationPrompt() {
    const page = usePage();

    const prompt = computed<App.Enums.PostRegistrationPrompt | null>(
        () => page.props.postRegistrationPrompt ?? null,
    );

    /**
     * Record that the prompt has been answered, whichever way. Idempotent server
     * side, so enrolling and then dismissing (or two tabs racing) is harmless.
     *
     * Names the prop it invalidates, because a cleared prompt is the *cached*
     * answer: without it the reply would be subject to the once exclusion and
     * the client would keep restoring the prompt it just answered.
     */
    function answer(): void {
        router.delete(answerPrompt().url, {
            only: POST_REGISTRATION_PROMPT_PROPS,
            preserveScroll: true,
            preserveState: true,
        });
    }

    return { prompt, answer };
}
