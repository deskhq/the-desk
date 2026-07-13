import { describe, expect, it, vi } from 'vitest';
import {
    adjacentSlug,
    dispatchKeydown,
    eventMatchesShortcut,
    isEditableTarget,
    matchShortcut,
    SHORTCUTS,
    shortcutsByCategory,
} from '@/composables/keyboardShortcuts';
import type {
    DispatchableEvent,
    ShortcutMatch,
} from '@/composables/keyboardShortcuts';

/**
 * Build a fake keydown event with sensible defaults so each test only states
 * the modifiers it cares about.
 */
function keydown(
    overrides: Partial<DispatchableEvent> = {},
): DispatchableEvent {
    return {
        key: 'a',
        metaKey: false,
        ctrlKey: false,
        altKey: false,
        shiftKey: false,
        target: null,
        preventDefault: vi.fn(),
        ...overrides,
    };
}

describe('eventMatchesShortcut', () => {
    it('matches on key case-insensitively', () => {
        const match: ShortcutMatch = { key: 'k', mod: true };

        expect(
            eventMatchesShortcut(keydown({ key: 'K', metaKey: true }), match),
        ).toBe(true);
    });

    it('treats meta and ctrl as the same command key', () => {
        const match: ShortcutMatch = { key: 'k', mod: true };

        expect(
            eventMatchesShortcut(keydown({ key: 'k', metaKey: true }), match),
        ).toBe(true);
        expect(
            eventMatchesShortcut(keydown({ key: 'k', ctrlKey: true }), match),
        ).toBe(true);
    });

    it('requires the command key when mod is set', () => {
        expect(
            eventMatchesShortcut(keydown({ key: 'k' }), {
                key: 'k',
                mod: true,
            }),
        ).toBe(false);
    });

    it('rejects the command key when mod is not set', () => {
        expect(
            eventMatchesShortcut(
                keydown({ key: 'ArrowDown', altKey: true, ctrlKey: true }),
                {
                    key: 'ArrowDown',
                    alt: true,
                },
            ),
        ).toBe(false);
    });

    it('enforces alt exactly', () => {
        expect(
            eventMatchesShortcut(keydown({ key: 'ArrowUp', altKey: true }), {
                key: 'ArrowUp',
                alt: true,
            }),
        ).toBe(true);
        expect(
            eventMatchesShortcut(keydown({ key: 'ArrowUp' }), {
                key: 'ArrowUp',
                alt: true,
            }),
        ).toBe(false);
    });

    it('ignores shift when the match leaves it unspecified', () => {
        expect(
            eventMatchesShortcut(keydown({ key: '?', shiftKey: true }), {
                key: '?',
            }),
        ).toBe(true);
        expect(
            eventMatchesShortcut(keydown({ key: '?', shiftKey: false }), {
                key: '?',
            }),
        ).toBe(true);
    });

    it('enforces shift when the match specifies it', () => {
        expect(
            eventMatchesShortcut(keydown({ key: 'a', shiftKey: true }), {
                key: 'a',
                shift: false,
            }),
        ).toBe(false);
    });
});

describe('matchShortcut', () => {
    it('returns the matching definition', () => {
        expect(matchShortcut(keydown({ key: 'k', metaKey: true }))?.id).toBe(
            'quick-switcher',
        );
        expect(
            matchShortcut(keydown({ key: 'ArrowDown', altKey: true }))?.id,
        ).toBe('next-channel');
        expect(
            matchShortcut(keydown({ key: 'ArrowUp', altKey: true }))?.id,
        ).toBe('previous-channel');
        expect(matchShortcut(keydown({ key: '?' }))?.id).toBe('show-shortcuts');
    });

    it('returns null when nothing matches', () => {
        expect(matchShortcut(keydown({ key: 'z' }))).toBeNull();
    });

    it('never dispatches a display-only shortcut', () => {
        // A bare ArrowUp is documented (edit last message) but composer-local,
        // so the global dispatcher must not claim it.
        expect(matchShortcut(keydown({ key: 'ArrowUp' }))).toBeNull();
    });
});

describe('isEditableTarget', () => {
    it.each(['INPUT', 'TEXTAREA', 'SELECT', 'input', 'textarea'])(
        'treats <%s> as editable',
        (tagName) => {
            expect(
                isEditableTarget({ tagName } as unknown as EventTarget),
            ).toBe(true);
        },
    );

    it('treats a contenteditable element as editable', () => {
        expect(
            isEditableTarget({
                tagName: 'DIV',
                isContentEditable: true,
            } as unknown as EventTarget),
        ).toBe(true);
    });

    it('treats a plain element as not editable', () => {
        expect(
            isEditableTarget({
                tagName: 'DIV',
                isContentEditable: false,
            } as unknown as EventTarget),
        ).toBe(false);
    });

    it('treats null and non-elements as not editable', () => {
        expect(isEditableTarget(null)).toBe(false);
        expect(isEditableTarget({} as EventTarget)).toBe(false);
    });
});

describe('dispatchKeydown', () => {
    it('runs the handler and prevents default for a matched shortcut', () => {
        const event = keydown({ key: '?' });
        const run = vi.fn();

        expect(dispatchKeydown(event, SHORTCUTS, run)).toBe(true);
        expect(run).toHaveBeenCalledWith('show-shortcuts');
        expect(event.preventDefault).toHaveBeenCalledOnce();
    });

    it('does nothing when no shortcut matches', () => {
        const event = keydown({ key: 'z' });
        const run = vi.fn();

        expect(dispatchKeydown(event, SHORTCUTS, run)).toBe(false);
        expect(run).not.toHaveBeenCalled();
        expect(event.preventDefault).not.toHaveBeenCalled();
    });

    it('suppresses non-modifier shortcuts while typing in a field', () => {
        const event = keydown({
            key: '?',
            target: { tagName: 'TEXTAREA' } as unknown as EventTarget,
        });
        const run = vi.fn();

        expect(dispatchKeydown(event, SHORTCUTS, run)).toBe(false);
        expect(run).not.toHaveBeenCalled();
        expect(event.preventDefault).not.toHaveBeenCalled();
    });

    it('still fires command-key shortcuts while typing in a field', () => {
        const event = keydown({
            key: 'k',
            metaKey: true,
            target: { tagName: 'TEXTAREA' } as unknown as EventTarget,
        });
        const run = vi.fn();

        expect(dispatchKeydown(event, SHORTCUTS, run)).toBe(true);
        expect(run).toHaveBeenCalledWith('quick-switcher');
    });
});

describe('adjacentSlug', () => {
    const slugs = ['general', 'random', 'design'];

    it('returns null for an empty list', () => {
        expect(adjacentSlug([], 'general', 1)).toBeNull();
    });

    it('steps forward and wraps past the end', () => {
        expect(adjacentSlug(slugs, 'general', 1)).toBe('random');
        expect(adjacentSlug(slugs, 'design', 1)).toBe('general');
    });

    it('steps backward and wraps past the start', () => {
        expect(adjacentSlug(slugs, 'random', -1)).toBe('general');
        expect(adjacentSlug(slugs, 'general', -1)).toBe('design');
    });

    it('falls back to an end of the list when the active slug is unknown', () => {
        expect(adjacentSlug(slugs, null, 1)).toBe('general');
        expect(adjacentSlug(slugs, 'missing', -1)).toBe('design');
    });
});

describe('shortcutsByCategory', () => {
    it('groups definitions by category in first-seen order', () => {
        const groups = shortcutsByCategory();

        expect(groups.map((group) => group.category)).toEqual([
            'Navigation',
            'Composer',
            'Help',
        ]);
        expect(groups[0].shortcuts.map((shortcut) => shortcut.id)).toEqual([
            'quick-switcher',
            'previous-channel',
            'next-channel',
        ]);
    });

    it('lists the composer-local edit shortcut for discoverability', () => {
        const composer = shortcutsByCategory().find(
            (group) => group.category === 'Composer',
        );

        expect(composer?.shortcuts.map((shortcut) => shortcut.id)).toEqual([
            'edit-last-message',
        ]);
    });
});
