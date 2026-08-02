import { useAutocompleteMenu } from '@/composables/useAutocompleteMenu';
import type { AutocompleteMenu } from '@/composables/useAutocompleteMenu';
import type { ComposerField } from '@/composables/useComposerField';
import { useUserGroups } from '@/composables/useUserGroups';
import type { Mention, PersonRef, RosterMember } from '@/types';

/**
 * Well-formed *person* mention token: `@[Display Name](user-id)`. The parser on
 * the server resolves the id; here it lets us collect the mentions being sent
 * and recognise a completed token so it never re-triggers the autocomplete. A
 * group token carries a `group:` prefix, so it deliberately does not match —
 * the optimistic row lists people, and the group's fan-out is resolved
 * server-side at post time.
 */
const MENTION_TOKEN = /@\[[^\]]+\]\(([0-9a-fA-F-]{36})\)/g;

/**
 * A fresh `@query` at the caret: an `@` at the start or after whitespace,
 * followed by run of non-space characters that isn't already a token.
 */
const MENTION_QUERY = /(?:^|\s)@([^\s@[\]()]*)$/;

const MAX_SUGGESTIONS = 8;

/**
 * How many of those slots user groups may claim. Capped so the menu stays a
 * people-picker first, but never so low that a matching group is crowded out.
 */
const MAX_GROUP_SUGGESTIONS = 3;

/**
 * One row of the `@` menu: a person, or a user group that fans the mention out
 * to everyone in it. Both live in one list so the keyboard model, the active
 * index, and the ARIA wiring stay single-source.
 */
export type MentionSuggestion =
    | { kind: 'user'; id: string; label: string; member: RosterMember }
    | {
          kind: 'group';
          id: string;
          label: string;
          group: App.Data.UserGroupData;
      };

export type ComposerMentions = {
    /** The `@` menu itself: rows, keyboard model, ARIA, selection. */
    menu: AutocompleteMenu<MentionSuggestion>;
    refreshSuggestions: () => void;
    collectMentions: (text: string) => Mention[];
    insertMention: (member: PersonRef) => void;
};

/**
 * The mention adapter over the composer's autocomplete engine: the `@` token
 * grammar, the roster a query is matched against, how a chosen row completes
 * into a mention token, and which people a body's tokens resolve to when it is
 * sent. The menu's own behaviour is `useAutocompleteMenu`'s.
 */
export function useComposerMentions(options: {
    field: ComposerField;
    /** The channel's mentionable members (bots excluded upstream). */
    members: () => RosterMember[];
}): ComposerMentions {
    const { body, caretPosition, focusRange } = options.field;
    const { search: searchGroups } = useUserGroups();

    const menu = useAutocompleteMenu<MentionSuggestion>({
        name: 'mention',
        onSelect: (suggestion) => completeMention(suggestion),
    });

    /**
     * The active `@query` immediately before the caret, or null when the caret is
     * not in a mention context.
     */
    function activeQuery(): { query: string; start: number } | null {
        const caret = caretPosition();
        const upToCaret = body.value.slice(0, caret);
        const match = upToCaret.match(MENTION_QUERY);

        if (!match) {
            return null;
        }

        return { query: match[1], start: caret - match[1].length - 1 };
    }

    function refreshSuggestions(): void {
        const active = activeQuery();

        if (!active) {
            menu.offer([]);

            return;
        }

        const needle = active.query.toLowerCase();

        // People first, then groups: naming an individual is the far more common
        // intent, and a group reaching several people should never be the row the
        // caret lands on by default.
        const people: MentionSuggestion[] = options
            .members()
            .filter((member) => member.name.toLowerCase().includes(needle))
            .map((member) => ({
                kind: 'user',
                id: member.id,
                label: member.name,
                member,
            }));

        const groups: MentionSuggestion[] = searchGroups(needle).map(
            (group) => ({
                kind: 'group',
                id: group.id,
                label: group.slug,
                group,
            }),
        );

        // Groups get reserved slots at the tail rather than whatever the people list
        // leaves over: a plain `[...people, ...groups].slice(MAX)` would hide a
        // matching group entirely behind eight matching names, making it
        // unreachable from the menu.
        const groupSlots = Math.min(groups.length, MAX_GROUP_SUGGESTIONS);

        menu.offer([
            ...people.slice(0, MAX_SUGGESTIONS - groupSlots),
            ...groups.slice(0, groupSlots),
        ]);
    }

    /** Replace the `@query` at the caret with the chosen row's mention token. */
    function completeMention(suggestion: MentionSuggestion): void {
        const caret = caretPosition();
        const active = activeQuery();

        if (!active) {
            return;
        }

        const before = body.value.slice(0, active.start);
        const after = body.value.slice(caret);
        // The `group:` prefix is what tells the server (and the renderer) to expand
        // the token to a whole group rather than resolve one person.
        const token =
            suggestion.kind === 'group'
                ? `@[${suggestion.label}](group:${suggestion.id}) `
                : `@[${suggestion.label}](${suggestion.id}) `;

        body.value = before + token + after;

        focusRange(before.length + token.length);
    }

    /**
     * Collect the distinct, resolvable mentions present in the body so the
     * optimistic row can highlight them before the server echo arrives.
     */
    function collectMentions(text: string): Mention[] {
        const seen = new Set<string>();
        const mentions: Mention[] = [];

        for (const match of text.matchAll(MENTION_TOKEN)) {
            const id = match[1];
            const member = options
                .members()
                .find((candidate) => candidate.id === id);

            if (member && !seen.has(id)) {
                seen.add(id);
                mentions.push({
                    id: member.id,
                    name: member.name,
                    avatar: member.avatar,
                });
            }
        }

        return mentions;
    }

    /**
     * Insert a mention token at the caret (or the end), keeping a space separator
     * from preceding text. Exposed so a profile hover card can drop a mention into
     * the composer from elsewhere in the page.
     */
    function insertMention(member: PersonRef): void {
        const caret = caretPosition();
        const before = body.value.slice(0, caret);
        const after = body.value.slice(caret);

        const separator = before.length > 0 && !before.endsWith(' ') ? ' ' : '';
        const token = `${separator}@[${member.name}](${member.id}) `;

        body.value = before + token + after;

        focusRange(before.length + token.length);
    }

    return { menu, refreshSuggestions, collectMentions, insertMention };
}
