/**
 * How a person renders on every dot surface.
 *
 * The server's `App.Enums.PresenceState` only describes someone who holds a
 * connection; whether they hold one at all is the Reverb roster's answer. This
 * union is the two composed, and is what the dot components take.
 */
export type RenderedPresence = App.Enums.PresenceState | 'offline';

/**
 * How the other side of a 1:1 DM renders.
 */
export function dmParticipantPresence(
    dmUserId: string | null | undefined,
    presenceFor: (userId: string) => RenderedPresence,
    ownPresence: RenderedPresence,
): RenderedPresence {
    // A self-DM has no other participant, so it renders viewer-relative all the
    // way down: the same fallback the avatar beside the dot already makes. The
    // person reading the page is never offline to themselves.
    return dmUserId != null ? presenceFor(dmUserId) : ownPresence;
}

/**
 * The message key announcing a presence to assistive tech.
 *
 * Returned untranslated so the caller resolves it through `$t` and the label
 * follows a live locale switch, the way every other surface's copy does.
 */
export function presenceLabelKey(presence: RenderedPresence): string {
    if (presence === 'active') {
        return 'Active';
    }

    return presence === 'away' ? 'Away' : 'Offline';
}
