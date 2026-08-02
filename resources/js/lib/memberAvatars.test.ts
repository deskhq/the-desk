import { describe, expect, it } from 'vitest';
import { memberAvatarStack } from '@/lib/memberAvatars';
import type { Mention } from '@/types';

/**
 * A member row for the masthead avatar stack.
 */
function member(id: string, name: string): Mention {
    return { id, name, avatar: null };
}

describe('memberAvatarStack', () => {
    it('shows every member and no overflow when the roster fits', () => {
        const members = [
            member('a', 'Alice'),
            member('b', 'Bob'),
            member('c', 'Carol'),
        ];

        expect(memberAvatarStack(members, 3)).toEqual({
            visible: members,
            overflow: 0,
        });
    });

    it('caps the visible avatars and rolls the rest into the overflow count', () => {
        const members = [
            member('a', 'Alice'),
            member('b', 'Bob'),
            member('c', 'Carol'),
            member('d', 'Dave'),
            member('e', 'Erin'),
        ];

        expect(memberAvatarStack(members, 3)).toEqual({
            visible: [members[0], members[1], members[2]],
            overflow: 2,
        });
    });

    it('never reports negative overflow for an empty roster', () => {
        expect(memberAvatarStack([], 3)).toEqual({
            visible: [],
            overflow: 0,
        });
    });

    it('treats a non-positive max as showing no avatars', () => {
        const members = [member('a', 'Alice'), member('b', 'Bob')];

        expect(memberAvatarStack(members, 0)).toEqual({
            visible: [],
            overflow: 2,
        });
    });
});
