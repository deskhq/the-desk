import { router } from '@inertiajs/vue3';
import { watch } from 'vue';
import { update as saveChannelDraft } from '@/actions/App/Http/Controllers/Channels/ChannelDraftController';
import { useDebouncedPost } from '@/composables/useDebouncedPost';
import { backgroundVisit } from '@/lib/backgroundVisit';

/**
 * How long to coalesce composer keystrokes before persisting the draft. Long
 * enough that ordinary typing collapses to a single request.
 */
export const DRAFT_DEBOUNCE_MS = 700;

export interface ChannelDraftOptions {
    /** The open channel's id; a change flushes the outgoing channel's draft. */
    channelId: () => string;
    /** The current team's slug, for the draft-save route. */
    teamSlug: () => string;
    /** The open channel's slug, tagged onto each scheduled save. */
    channelSlug: () => string;
}

export interface ChannelDraft {
    /** Debounce a draft save for the current channel; an empty body clears it. */
    onDraftChange: (body: string) => void;
    /** Drop a pending save without firing it (a send clears the draft server-side). */
    cancel: () => void;
    /**
     * Immediately clear the current channel's saved draft (cancelling any pending
     * save). Used when a send is queued offline: the store endpoint that normally
     * clears the draft isn't reached, so a refresh would otherwise repopulate the
     * composer with the already-queued text.
     */
    clear: () => void;
}

/**
 * Own the composer draft's persistence lifecycle: debounce keystrokes into a
 * single per-channel save, flush the outgoing channel's draft when the open
 * channel changes, and flush again on teardown so a just-typed draft is never
 * lost. Rides {@see useDebouncedPost} — the payload is tagged with the slug of
 * the channel being edited, so a flush triggered by a channel switch still writes
 * to the channel that was actually being typed in rather than the one just
 * navigated to.
 */
export function useChannelDraft(options: ChannelDraftOptions): ChannelDraft {
    function persistDraft(slug: string, body: string): void {
        router.patch(
            saveChannelDraft({ team: options.teamSlug(), channel: slug }).url,
            { body },
            {
                // The channel-switch watcher below flushes this save *during* the
                // navigation it must not cancel, so it can never ride the
                // synchronous queue; see {@see backgroundVisit}.
                ...backgroundVisit,
                preserveScroll: true,
                preserveState: true,
                only: ['channels'],
            },
        );
    }

    // The payload carries the slug of the channel it belongs to, so a flush on
    // channel switch writes to the right place. It flushes on unmount so a
    // just-typed draft outlives leaving the workspace.
    const draftPost = useDebouncedPost(
        (draft: { slug: string; body: string }) =>
            persistDraft(draft.slug, draft.body),
        { delay: DRAFT_DEBOUNCE_MS, flushOnUnmount: true },
    );

    function onDraftChange(body: string): void {
        draftPost.schedule({ slug: options.channelSlug(), body });
    }

    function clear(): void {
        draftPost.cancel();
        persistDraft(options.channelSlug(), '');
    }

    // Persist the outgoing channel's draft before the switch settles; the pending
    // payload still carries the old channel's slug.
    watch(options.channelId, () => draftPost.flush());

    return { onDraftChange, cancel: draftPost.cancel, clear };
}
