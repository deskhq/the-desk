export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

/**
 * A toast flashed by the server through `Inertia::flash('toast', …)`. The tones
 * are the ones `useToast` speaks: `progress` is unreachable from a completed
 * request, and there is no `info` tone in the design's vocabulary (nothing has
 * ever flashed one).
 */
export type FlashToast = {
    type: 'success' | 'warning' | 'error';
    message: string;
};
