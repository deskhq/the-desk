import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { useDebouncedPost } from '@/composables/useDebouncedPost';
import type {
    DebouncedPost,
    DebouncedPostOptions,
} from '@/composables/useDebouncedPost';

/**
 * Run the composable inside a disposable effect scope so `onScopeDispose` — the
 * auto-teardown — can be exercised by stopping the scope, standing in for a
 * component unmount.
 */
function withScope<T>(
    run: (payload: T) => void,
    options: DebouncedPostOptions,
): { post: DebouncedPost<T>; unmount: () => void } {
    const scope = effectScope();
    let post!: DebouncedPost<T>;

    scope.run(() => {
        post = useDebouncedPost(run, options);
    });

    return { post, unmount: () => scope.stop() };
}

describe('useDebouncedPost', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('runs the action once, with the payload, after the delay elapses', () => {
        const run = vi.fn();
        const { post } = withScope<string>(run, { delay: 400 });

        post.schedule('a');
        expect(run).not.toHaveBeenCalled();

        vi.advanceTimersByTime(399);
        expect(run).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(run).toHaveBeenCalledExactlyOnceWith('a');
    });

    it('coalesces a burst into a single run carrying the latest payload', () => {
        const run = vi.fn();
        const { post } = withScope<string>(run, { delay: 400 });

        post.schedule('first');
        vi.advanceTimersByTime(200);
        post.schedule('second');
        vi.advanceTimersByTime(200);
        // The window restarted on the second call, so nothing has fired yet.
        expect(run).not.toHaveBeenCalled();

        vi.advanceTimersByTime(200);
        expect(run).toHaveBeenCalledExactlyOnceWith('second');
    });

    it('flushes the pending payload immediately and cancels the timer', () => {
        const run = vi.fn();
        const { post } = withScope<string>(run, { delay: 400 });

        post.schedule('now');
        post.flush();
        expect(run).toHaveBeenCalledExactlyOnceWith('now');

        // The timer was cancelled by the flush, so it never fires a second time.
        vi.advanceTimersByTime(400);
        expect(run).toHaveBeenCalledOnce();
    });

    it('flush is a no-op when nothing is pending', () => {
        const run = vi.fn();
        const { post } = withScope<string>(run, { delay: 400 });

        post.flush();
        expect(run).not.toHaveBeenCalled();
    });

    it('cancel drops a pending run without firing it', () => {
        const run = vi.fn();
        const { post } = withScope<string>(run, { delay: 400 });

        post.schedule('dropped');
        post.cancel();

        vi.advanceTimersByTime(400);
        expect(run).not.toHaveBeenCalled();
    });

    it('drops a call made while the gate is closed, leaving no timer', () => {
        const run = vi.fn();
        let focused = false;
        const { post } = withScope<string>(run, {
            delay: 400,
            gate: () => focused,
        });

        post.schedule('blurred');
        vi.advanceTimersByTime(400);
        expect(run).not.toHaveBeenCalled();

        focused = true;
        post.schedule('focused');
        vi.advanceTimersByTime(400);
        expect(run).toHaveBeenCalledExactlyOnceWith('focused');
    });

    it('cancels a pending run on scope teardown by default', () => {
        const run = vi.fn();
        const { post, unmount } = withScope<string>(run, { delay: 400 });

        post.schedule('pending');
        unmount();

        vi.advanceTimersByTime(400);
        expect(run).not.toHaveBeenCalled();
    });

    it('flushes a pending run on scope teardown when flushOnUnmount is set', () => {
        const run = vi.fn();
        const { post, unmount } = withScope<string>(run, {
            delay: 400,
            flushOnUnmount: true,
        });

        post.schedule('kept');
        unmount();

        expect(run).toHaveBeenCalledExactlyOnceWith('kept');
    });
});
