/**
 * The minimal shape an overlapping avatar stack needs from a person: an id to
 * key on and a name to derive initials from. `Mention` and `App.Data.UserData`
 * both satisfy it, so any roster can feed the stack.
 */
export type StackMember = {
    id: string;
    name: string;
    avatar?: string | null;
    // Whether this member is a bot, so the stack can square its avatar and show a
    // glyph. Absent (falsy) for humans.
    isBot?: boolean;
};

/**
 * The overlapping member avatars: the first `max` members render as circles and
 * the remainder collapse into a single `+N` overflow chip.
 */
export type MemberAvatarStack = {
    visible: StackMember[];
    overflow: number;
};

/**
 * Split a roster into the avatars a stack shows and the count it hides behind a
 * `+N` chip.
 *
 * At most `max` members render as avatars (a non-positive `max` shows none); the
 * rest become the overflow count, which never drops below zero.
 */
export function memberAvatarStack(
    members: StackMember[],
    max: number,
): MemberAvatarStack {
    const visible = max > 0 ? members.slice(0, max) : [];

    return {
        visible,
        overflow: members.length - visible.length,
    };
}
