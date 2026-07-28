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
    // Every 1:1 carries a counterpart id — a self-DM resolves it to the viewer
    // themselves — so a missing id means a group conversation, whose surfaces
    // render a facepile rather than this dot. Falling back to the viewer's own
    // presence keeps a defensive render from ever being a wrong "offline".
    return dmUserId != null ? presenceFor(dmUserId) : ownPresence;
}

/**
 * How many of a roster are active right now.
 *
 * Away counts as present but not active, which is the whole point of the third
 * state: the readout answers "who could answer me now", not "who has the tab
 * open". Shared by the masthead's facepile and the compact readout that stands
 * in for it below the breakpoint, which must never disagree.
 */
export function activeMemberCount(
    members: Array<{ id: string }>,
    presenceFor: (userId: string) => RenderedPresence,
): number {
    return members.filter((member) => presenceFor(member.id) === 'active')
        .length;
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
