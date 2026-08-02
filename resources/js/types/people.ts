/**
 * A minimal reference to a team member, feeding the DM entry points.
 *
 * A deliberate client-side *narrowing* of the `UserData` the shared `teamMembers`
 * prop carries, not a restatement of it: the DM pickers assemble refs of their own
 * from whatever they have in hand, which is a name and an id. That is why the two
 * presence fields are optional here while `App.Data.UserData` always answers them.
 * A surface that wants the whole member reads `RosterMember`.
 */
export type PersonRef = {
    id: string;
    name: string;
    /**
     * The server's resolved active/away answer for this member, seeding the dot
     * surfaces for a client that has only just loaded. Absent on the hand-built
     * refs some pickers assemble, which then read as active.
     */
    presence?: App.Enums.PresenceState;
    /**
     * Whether the member is in do-not-disturb, driving the crescent badge on
     * the dot surfaces. Absent on hand-built refs, which then show no badge.
     */
    isDnd?: boolean;
};

/**
 * A ranked DM target: a team member paired with whether they are the viewer
 * themselves (rendered as "You" and opening a self-DM).
 */
export type PersonEntry = PersonRef & {
    isSelf: boolean;
};
