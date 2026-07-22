/**
 * A minimal reference to a team member, as shipped in the shared `teamMembers`
 * prop (mirrors `App\Data\UserData`). Feeds the DM entry points.
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
