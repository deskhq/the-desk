import { toast as sonner } from 'vue-sonner';

/**
 * The single action a toast may carry. One only — when several would apply the
 * precedence is Undo, then Retry, then View.
 */
export type ToastAction = {
    /** Already-translated label, e.g. `t('Undo')`. */
    label: string;
    run: () => void;
};

export type ToastOptions = {
    /**
     * The value that was just set, rendered under the title: "Reminder set"
     * carries "Tomorrow, 9:00 AM".
     */
    detail?: string;
    action?: ToastAction;
    /**
     * Merge identity. A repeat under the same key replaces the toast on screen
     * instead of queueing behind it, so ten failing uploads are one toast.
     */
    key?: string;
    /** Overrides the duration policy below. Only pass one with a reason. */
    duration?: number;
};

/** A confirmation the user does not have to act on. */
const CONFIRMATION_MS = 4_000;

/** Long enough to read the outcome and still reach for Undo, Retry or View. */
const ACTIONABLE_MS = 7_000;

/** Errors and work in progress stay up until they are dismissed or resolve. */
const UNTIL_DISMISSED = Infinity;

type ToastTone = 'success' | 'error' | 'warning' | 'progress';

/**
 * The design's vocabulary, mapped onto sonner's. `progress` is sonner's
 * `loading`; the other three line up by name.
 */
const SONNER_VARIANT: Record<
    ToastTone,
    (message: string, options: Record<string, unknown>) => unknown
> = {
    success: sonner.success,
    error: sonner.error,
    warning: sonner.warning,
    progress: sonner.loading,
};

/**
 * The duration policy lives here rather than at the call sites, so a toast's
 * lifetime follows from what it is rather than from who raised it. Tone wins
 * over the action: an error carrying Retry is still an error, and a Retry the
 * user never saw is worse than no Retry at all.
 */
function durationFor(tone: ToastTone, options: ToastOptions): number {
    if (options.duration !== undefined) {
        return options.duration;
    }

    if (tone === 'error' || tone === 'progress') {
        return UNTIL_DISMISSED;
    }

    return options.action ? ACTIONABLE_MS : CONFIRMATION_MS;
}

function notify(tone: ToastTone, title: string, options: ToastOptions): void {
    SONNER_VARIANT[tone](title, {
        id: options.key,
        description: options.detail,
        duration: durationFor(tone, options),
        action: options.action
            ? { label: options.action.label, onClick: options.action.run }
            : undefined,
    });
}

/**
 * The single entry point for every toast in the app: nothing outside
 * `components/ui/sonner/` may reach for `vue-sonner` directly (enforced by
 * `no-restricted-imports`, see `eslint-rules/vue-sonner-policy.test.ts`).
 * Routing every call site through here is what gives the duration policy, the
 * merge key and the action slot one place to live.
 *
 * Copy stays with the caller: titles and details arrive already translated.
 */
export function useToast() {
    return {
        success: (title: string, options: ToastOptions = {}): void =>
            notify('success', title, options),
        error: (title: string, options: ToastOptions = {}): void =>
            notify('error', title, options),
        warning: (title: string, options: ToastOptions = {}): void =>
            notify('warning', title, options),
        progress: (title: string, options: ToastOptions = {}): void =>
            notify('progress', title, options),
    };
}
