/**
 * How a person renders on every dot surface.
 *
 * The server's `App.Enums.PresenceState` only describes someone who holds a
 * connection; whether they hold one at all is the Reverb roster's answer. This
 * union is the two composed, and is what the dot components take.
 */
export type RenderedPresence = App.Enums.PresenceState | 'offline';

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
