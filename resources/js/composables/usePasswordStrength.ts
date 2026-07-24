import { readonly, ref, watch } from 'vue';
import type { DeepReadonly, Ref } from 'vue';
import { translate } from '@/lib/i18n';

/**
 * zxcvbn scores 0-4, which the meter draws as four segments: none filled at 0,
 * all four at 4.
 */
export const SEGMENT_COUNT = 4;

/** A zxcvbn strength score. */
export type PasswordScore = 0 | 1 | 2 | 3 | 4;

export type UsePasswordStrengthReturn = {
    /** The current score, or null while the password is empty or unscored. */
    score: DeepReadonly<Ref<PasswordScore | null>>;
    /** The translated name of the current score, or null when there is none. */
    label: DeepReadonly<Ref<string | null>>;
};

const LABELS = [
    'Too weak',
    'Weak',
    'Fair',
    'Strong enough',
    'Very strong',
] as const;

/**
 * Name a score in the reader's language.
 */
export function strengthLabel(score: PasswordScore): string {
    return translate(LABELS[score]);
}

/**
 * The estimator, loaded on first use.
 *
 * zxcvbn's dictionaries are far larger than the auth pages themselves, so they
 * are imported only once someone actually starts typing a password — the login
 * screen, which has no meter, never pays for them.
 */
let estimator: Promise<(password: string) => PasswordScore> | null = null;

function loadEstimator(): Promise<(password: string) => PasswordScore> {
    estimator ??= Promise.all([
        import('@zxcvbn-ts/core'),
        import('@zxcvbn-ts/language-common'),
        import('@zxcvbn-ts/language-en'),
    ]).then(([{ ZxcvbnFactory }, common, en]) => {
        const zxcvbn = new ZxcvbnFactory({
            dictionary: {
                ...common.default.dictionary,
                ...en.default.dictionary,
            },
            graphs: common.default.adjacencyGraphs,
            translations: en.default.translations,
        });

        return (password: string) => zxcvbn.check(password).score;
    });

    return estimator;
}

/**
 * Score a password as it is typed, for the advisory meter on the register and
 * reset screens.
 *
 * This never gates a submission: the server's `Password::defaults()` rule is the
 * only thing that decides whether a password is acceptable. A low score is a
 * nudge, and a stale one is harmless, so a score that arrives after the password
 * has moved on is discarded rather than shown.
 */
export function usePasswordStrength(
    password: Ref<string>,
): UsePasswordStrengthReturn {
    const score = ref<PasswordScore | null>(null);
    const label = ref<string | null>(null);

    watch(
        password,
        (value) => {
            if (value === '') {
                score.value = null;
                label.value = null;

                return;
            }

            void loadEstimator().then((estimate) => {
                if (password.value !== value) {
                    return;
                }

                score.value = estimate(value);
                label.value = strengthLabel(score.value);
            });
        },
        { immediate: true },
    );

    return { score: readonly(score), label: readonly(label) };
}
