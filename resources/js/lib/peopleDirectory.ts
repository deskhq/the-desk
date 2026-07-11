import { rankChannels } from '@/composables/quickSwitcher';
import type { PersonEntry, PersonRef } from '@/types/people';

/**
 * Filter and rank team members for the DM entry points (the sidebar people
 * picker and the ⌘K "People" group), tagging the viewer's own entry so it can
 * render as "You".
 *
 * Ranking reuses the quick-switcher's name scorer (exact > prefix >
 * word-boundary > substring > subsequence, ties alphabetical), so people rank
 * consistently with channels. An empty query returns every member in
 * alphabetical order.
 */
export function rankPeople(
    members: readonly PersonRef[],
    query: string,
    currentUserId: string,
): PersonEntry[] {
    return rankChannels(members, query).map((member) => ({
        id: member.id,
        name: member.name,
        isSelf: member.id === currentUserId,
    }));
}
