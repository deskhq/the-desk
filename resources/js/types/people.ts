/**
 * A minimal reference to a team member, as shipped in the shared `teamMembers`
 * prop (mirrors `App\Data\UserData`). Feeds the DM entry points.
 */
export type PersonRef = {
    id: string;
    name: string;
};

/**
 * A ranked DM target: a team member paired with whether they are the viewer
 * themselves (rendered as "You" and opening a self-DM).
 */
export type PersonEntry = PersonRef & {
    isSelf: boolean;
};
