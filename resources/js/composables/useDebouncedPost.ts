import { onScopeDispose } from 'vue';

export interface DebouncedPostOptions {
    /** Milliseconds to coalesce a burst of `schedule` calls into a single run. */
    delay: number;
    /**
     * Checked at `schedule` time: when it returns false the call is dropped and
     * any already-pending run is left untouched. Used to gate reads on tab focus
     * (`() => document.hasFocus()`) so a channel is only marked read while looked
     * at. Omit for an ungated post.
     */
    gate?: () => boolean;
    /**
     * Fire a pending run on teardown instead of dropping it. Draft persistence
     * sets this so a just-typed draft outlives an unmount; reads leave it false so
     * a stale mark-read never fires after the view is gone.
     */
    flushOnUnmount?: boolean;
}

export interface DebouncedPost<T> {
    /** Restart the debounce window, remembering `payload` as the latest to run. */
    schedule: (payload: T) => void;
    /** Run the pending payload now, cancelling the timer; a no-op when idle. */
    flush: () => void;
    /** Drop any pending run without firing it. */
    cancel: () => void;
}

/**
 * The one debounced, optionally gated, auto-torn-down `router` POST/PATCH behind
 * mark-read, mark-thread-read, draft persistence, sidebar-badge refresh, and
 * search — each of which had hand-rolled its own timer handle, `clearTimeout`
 * dance, and `onBeforeUnmount` cleanup.
 *
 * `run` receives the latest scheduled payload; a burst of `schedule` calls within
 * `delay` collapses to one run. The timer is owned here and disposed with the
 * surrounding effect scope (component unmount), so callers keep only *what* to
 * post, not the bookkeeping.
 */
export function useDebouncedPost<T = void>(
    run: (payload: T) => void,
    options: DebouncedPostOptions,
): DebouncedPost<T> {
    let timer: ReturnType<typeof setTimeout> | null = null;
    let pending: { payload: T } | null = null;

    function clearTimer(): void {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function fire(): void {
        if (!pending) {
            return;
        }

        const { payload } = pending;
        pending = null;
        run(payload);
    }

    function schedule(payload: T): void {
        if (options.gate && !options.gate()) {
            return;
        }

        pending = { payload };
        clearTimer();
        timer = setTimeout(() => {
            timer = null;
            fire();
        }, options.delay);
    }

    function flush(): void {
        clearTimer();
        fire();
    }

    function cancel(): void {
        clearTimer();
        pending = null;
    }

    onScopeDispose(() => {
        if (options.flushOnUnmount) {
            flush();
        } else {
            cancel();
        }
    });

    return { schedule, flush, cancel };
}
