import { usePasskeyRegister } from '@laravel/passkeys/vue';
import type { Ref } from 'vue';
import { useTranslations } from '@/composables/useTranslations';
import { registrationOptions, store } from '@/routes/passkey';

export type PasskeyEnrolment = {
    /**
     * Run the WebAuthn registration ceremony for a passkey under this name. The
     * name is trimmed; a blank one is refused with an inline error rather than
     * raising the browser's sheet for a passkey nothing could identify later.
     */
    enrol: (name: string) => Promise<void>;
    /** Whether a ceremony is in flight — the browser's own sheet is up. */
    isLoading: Ref<boolean>;
    /** The last failure, or the blank-name refusal. Null while all is well. */
    error: Ref<string | null>;
    /** Whether this browser can do WebAuthn at all. False until mounted. */
    isSupported: Ref<boolean>;
};

/**
 * The passkey enrolment ceremony, wrapped once for every surface that offers it:
 * the Security settings panel and the post-registration prompt. Extracted so the
 * two cannot drift on the route wiring or the blank-name guard.
 *
 * Named `usePasskeyEnrolment` rather than `usePasskeyRegister` on purpose — the
 * vendor hook it wraps already owns that name.
 *
 * @param onEnrolled Called with the name the passkey was saved under.
 */
export function usePasskeyEnrolment(
    onEnrolled?: (name: string) => void,
): PasskeyEnrolment {
    const { t } = useTranslations();

    /**
     * The name the in-flight ceremony was started with. The vendor hook's success
     * callback carries no payload, so this is what lets a caller name the passkey
     * that was just saved.
     */
    let enrolling = '';

    const { register, isLoading, error, isSupported } = usePasskeyRegister({
        routes: {
            options: registrationOptions().url,
            submit: store().url,
        },
        onSuccess: () => onEnrolled?.(enrolling),
    });

    async function enrol(name: string): Promise<void> {
        error.value = null;

        const trimmed = name.trim();

        if (trimmed === '') {
            error.value = t(
                'Give this passkey a name so you can recognise it later.',
            );

            return;
        }

        enrolling = trimmed;

        await register(trimmed);
    }

    return { enrol, isLoading, error, isSupported };
}
