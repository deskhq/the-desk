/**
 * "The Desk" branded error pages — the per-status content set.
 *
 * Each covered HTTP status maps to a heading, a supporting message, and the set
 * of call-to-action affordances the page offers. Copy is stored as English
 * source strings (the i18n keys), so `Error.vue` translates them through `$t`
 * and a missing catalog entry still reads as English.
 */

/**
 * A call-to-action rendered as a pill on an error page.
 *
 * - `workspace` links back to the user's workspace (or home for guests).
 * - `reload` re-runs the failed request by reloading the current page.
 *
 * `primary` actions use the filled ink pill; the rest use the outline pill.
 */
export type ErrorAction = {
    labelKey: string;
    kind: 'workspace' | 'reload';
    primary: boolean;
};

export type ErrorContent = {
    heading: string;
    message: string;
    actions: ErrorAction[];
};

const WORKSPACE_ACTION: ErrorAction = {
    labelKey: 'Back to your workspace',
    kind: 'workspace',
    primary: true,
};

/**
 * The content variants keyed by status. 500/503 are included so the component
 * can render them too, even though they are normally served by the
 * self-contained Blade fallbacks (`resources/views/errors/*.blade.php`).
 */
const CONTENT: Record<number, ErrorContent> = {
    403: {
        heading: 'This room is members-only',
        message:
            'You don’t have permission to view this page. Ask a workspace admin to invite you.',
        actions: [WORKSPACE_ACTION],
    },
    404: {
        heading: 'This page has left the channel',
        message:
            'The page you’re looking for doesn’t exist or has been moved. Check the address, or head back to your workspace.',
        actions: [WORKSPACE_ACTION],
    },
    419: {
        heading: 'This page went stale',
        message:
            'Your session expired while the page was open. Refresh and try that action again.',
        actions: [{ labelKey: 'Refresh page', kind: 'reload', primary: true }],
    },
    429: {
        heading: 'Easy there — take a breath',
        message:
            'You’ve made too many requests in a short time. Wait a minute, then try again.',
        actions: [{ labelKey: 'Try again', kind: 'reload', primary: true }],
    },
    500: {
        heading: 'Something broke on our desk',
        message:
            'An unexpected error occurred on our end. Your messages are safe — try again in a moment.',
        actions: [
            { labelKey: 'Try again', kind: 'reload', primary: true },
            { ...WORKSPACE_ACTION, primary: false },
        ],
    },
    503: {
        heading: 'We’re tidying the desk',
        message:
            'The Desk is briefly down for maintenance. We’ll be back within the hour — no messages will be lost.',
        actions: [],
    },
};

/**
 * A neutral fallback for any status without a bespoke variant, so an unexpected
 * code still renders a branded page rather than an empty shell.
 */
const FALLBACK: ErrorContent = {
    heading: 'Something went wrong',
    message:
        'An unexpected error occurred. Head back to your workspace and try again.',
    actions: [WORKSPACE_ACTION],
};

/**
 * Resolve the branded content for an HTTP status, falling back to a neutral
 * variant for any status without a bespoke entry.
 */
export function errorContentFor(status: number): ErrorContent {
    return CONTENT[status] ?? FALLBACK;
}
