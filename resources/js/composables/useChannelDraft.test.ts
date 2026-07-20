import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import type { Ref } from 'vue';

const { patch } = vi.hoisted(() => ({ patch: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { patch } }));

import {
    DRAFT_DEBOUNCE_MS,
    useChannelDraft,
} from '@/composables/useChannelDraft';
import type { ChannelDraft } from '@/composables/useChannelDraft';
import { backgroundVisit } from '@/lib/backgroundVisit';

/**
 * Run the composable inside a disposable effect scope so its channel-switch
 * watcher and the underlying auto-teardown are exercised by stopping the scope.
 */
function withScope(channelSlug: Ref<string>, channelId: Ref<string>) {
    const scope = effectScope();
    let draft!: ChannelDraft;

    scope.run(() => {
        draft = useChannelDraft({
            channelId: () => channelId.value,
            teamSlug: () => 'acme',
            channelSlug: () => channelSlug.value,
        });
    });

    return { draft, unmount: () => scope.stop() };
}

/** The url of the nth recorded draft-save patch. */
function patchedUrl(call = 0): string {
    return patch.mock.calls[call][0] as string;
}

describe('useChannelDraft', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        patch.mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('debounces a draft save for the current channel', () => {
        const { draft } = withScope(ref('alpha'), ref('id-alpha'));

        draft.onDraftChange('half a th');
        draft.onDraftChange('half a thought');
        expect(patch).not.toHaveBeenCalled();

        vi.advanceTimersByTime(DRAFT_DEBOUNCE_MS);

        expect(patch).toHaveBeenCalledOnce();
        expect(patchedUrl()).toContain('alpha');
        expect(patch.mock.calls[0][1]).toEqual({ body: 'half a thought' });
    });

    it('flushes the outgoing channel draft with its own slug on a channel switch', () => {
        const slug = ref('alpha');
        const id = ref('id-alpha');
        const { draft } = withScope(slug, id);

        // Type into alpha, then switch to bravo before the debounce fires.
        draft.onDraftChange('for alpha');
        slug.value = 'bravo';
        id.value = 'id-bravo';

        return nextTick().then(() => {
            // The pending save flushed immediately, tagged with alpha's slug —
            // not bravo, the channel just navigated to.
            expect(patch).toHaveBeenCalledOnce();
            expect(patchedUrl()).toContain('alpha');
            expect(patchedUrl()).not.toContain('bravo');
        });
    });

    it('cancels a pending save so a send does not re-persist cleared text', () => {
        const { draft } = withScope(ref('alpha'), ref('id-alpha'));

        draft.onDraftChange('unsent');
        draft.cancel();

        vi.advanceTimersByTime(DRAFT_DEBOUNCE_MS);

        expect(patch).not.toHaveBeenCalled();
    });

    it('flushes a just-typed draft on teardown so it is not lost', () => {
        const { draft, unmount } = withScope(ref('alpha'), ref('id-alpha'));

        draft.onDraftChange('outlives unmount');
        unmount();

        expect(patch).toHaveBeenCalledOnce();
        expect(patch.mock.calls[0][1]).toEqual({ body: 'outlives unmount' });
    });

    it('saves in the background so it never interrupts a navigation', () => {
        const { draft } = withScope(ref('alpha'), ref('id-alpha'));

        draft.onDraftChange('typed while leaving');
        vi.advanceTimersByTime(DRAFT_DEBOUNCE_MS);

        // A draft save flushes on the very channel switch it must not cancel, so
        // it has to stay off the synchronous queue (#586).
        expect(patch.mock.calls[0][2]).toMatchObject(backgroundVisit);
    });

    it('clears the saved draft immediately, dropping any pending save', () => {
        const { draft } = withScope(ref('alpha'), ref('id-alpha'));

        draft.onDraftChange('queued offline');
        draft.clear();

        // The clear fires straight away with an empty body...
        expect(patch).toHaveBeenCalledOnce();
        expect(patch.mock.calls[0][1]).toEqual({ body: '' });
        expect(patchedUrl()).toContain('alpha');

        // ...and the cancelled debounce never re-persists the queued text.
        vi.advanceTimersByTime(DRAFT_DEBOUNCE_MS);
        expect(patch).toHaveBeenCalledOnce();
    });
});
