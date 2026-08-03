/**
 * App-wide keyboard shortcuts. The definitions here are the single source of
 * truth shared by the dispatcher ({@link dispatchKeydown}) and the help modal,
 * so a shortcut can never appear in one without the other.
 */
export type ShortcutId =
    | 'command-palette'
    | 'previous-channel'
    | 'next-channel'
    | 'focus-notifications'
    | 'edit-last-message'
    | 'show-shortcuts';

/**
 * How a {@link KeyboardEvent} is matched to a shortcut. `mod` means the
 * platform command key (⌘ on macOS, Ctrl elsewhere). `alt` and `shift`, when
 * omitted, must be absent — except `shift`, which is left unconstrained when
 * omitted because printable keys such as `?` already imply it on many layouts.
 */
export interface ShortcutMatch {
    key: string;
    mod?: boolean;
    alt?: boolean;
    shift?: boolean;
}

export interface ShortcutDefinition {
    id: ShortcutId;
    category: string;
    description: string;
    /** Display tokens for the help modal, e.g. `['⌘', 'K']`. */
    keys: string[];
    match: ShortcutMatch;
    /**
     * Documentation-only entries are listed in the help modal but never
     * dispatched globally — the shortcut is handled locally elsewhere (the
     * composer's own ArrowUp handler owns "edit last message"), so the global
     * dispatcher must not claim the bare keypress.
     */
    displayOnly?: boolean;
    /**
     * Whether the shortcut still fires while the user is typing in a field.
     * Non-modifier shortcuts are otherwise suppressed there so they never hijack
     * a keystroke mid-word — which a function key cannot do, and which F6 in
     * particular must not be: the composer is exactly where the user is standing
     * when a send fails, and the toast offering Retry is the thing F6 reaches.
     */
    firesWhileTyping?: boolean;
}

/**
 * The minimal event surface the dispatcher reads, so callers (and tests) can
 * pass either a real {@link KeyboardEvent} or a plain object.
 */
export type DispatchableEvent = Pick<
    KeyboardEvent,
    'key' | 'metaKey' | 'ctrlKey' | 'altKey' | 'shiftKey' | 'target'
> & { preventDefault(): void };

export const SHORTCUTS: readonly ShortcutDefinition[] = [
    {
        id: 'command-palette',
        category: 'Navigation',
        description: 'Open the command palette',
        keys: ['⌘', 'K'],
        match: { key: 'k', mod: true },
    },
    {
        id: 'previous-channel',
        category: 'Navigation',
        description: 'Go to previous channel',
        keys: ['⌥', '↑'],
        match: { key: 'ArrowUp', alt: true },
    },
    {
        id: 'next-channel',
        category: 'Navigation',
        description: 'Go to next channel',
        keys: ['⌥', '↓'],
        match: { key: 'ArrowDown', alt: true },
    },
    {
        id: 'focus-notifications',
        category: 'Navigation',
        description: 'Focus notifications',
        keys: ['F6'],
        match: { key: 'F6' },
        firesWhileTyping: true,
    },
    {
        id: 'edit-last-message',
        category: 'Composer',
        description: 'Edit your last message',
        keys: ['↑'],
        // Handled locally by the composer, so it is never dispatched globally.
        match: { key: 'ArrowUp' },
        displayOnly: true,
    },
    {
        id: 'show-shortcuts',
        category: 'Help',
        description: 'Show keyboard shortcuts',
        keys: ['?'],
        match: { key: '?' },
    },
];

/**
 * Whether `target` is a field the user is typing into. Non-modifier shortcuts
 * are suppressed for these so they never hijack a keystroke mid-word. Duck-typed
 * rather than using `instanceof HTMLElement` so it works without a global DOM.
 */
export function isEditableTarget(target: EventTarget | null): boolean {
    const element = target as {
        tagName?: string;
        isContentEditable?: boolean;
    } | null;

    if (!element || typeof element.tagName !== 'string') {
        return false;
    }

    const tag = element.tagName.toUpperCase();

    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
        return true;
    }

    return element.isContentEditable === true;
}

/**
 * Whether `event` satisfies `match`. The command key is treated as
 * meta-or-ctrl so a single definition covers both macOS and other platforms.
 */
export function eventMatchesShortcut(
    event: DispatchableEvent,
    match: ShortcutMatch,
): boolean {
    if (event.key.toLowerCase() !== match.key.toLowerCase()) {
        return false;
    }

    const modActive = event.metaKey || event.ctrlKey;

    if (Boolean(match.mod) !== modActive) {
        return false;
    }

    if (Boolean(match.alt) !== event.altKey) {
        return false;
    }

    if (match.shift !== undefined && match.shift !== event.shiftKey) {
        return false;
    }

    return true;
}

/**
 * The first shortcut `event` matches, or `null` when none do.
 */
export function matchShortcut(
    event: DispatchableEvent,
    shortcuts: readonly ShortcutDefinition[] = SHORTCUTS,
): ShortcutDefinition | null {
    return (
        shortcuts.find(
            (shortcut) =>
                !shortcut.displayOnly &&
                eventMatchesShortcut(event, shortcut.match),
        ) ?? null
    );
}

/**
 * Resolve `event` against `shortcuts` and, when one fires, prevent the default
 * and invoke `run` with its id. Non-modifier shortcuts are ignored while the
 * user is typing in a field; command-key shortcuts (like ⌘K) still fire there.
 * Returns whether a shortcut was dispatched.
 */
export function dispatchKeydown(
    event: DispatchableEvent,
    shortcuts: readonly ShortcutDefinition[],
    run: (id: ShortcutId) => void,
): boolean {
    const shortcut = matchShortcut(event, shortcuts);

    if (!shortcut) {
        return false;
    }

    if (
        isEditableTarget(event.target) &&
        !shortcut.match.mod &&
        !shortcut.firesWhileTyping
    ) {
        return false;
    }

    event.preventDefault();
    run(shortcut.id);

    return true;
}

/**
 * The slug `delta` steps away from `activeSlug` in `slugs`, wrapping at either
 * end. Returns `null` for an empty list; falls back to the first (or last) slug
 * when `activeSlug` is not present.
 */
export function adjacentSlug(
    slugs: readonly string[],
    activeSlug: string | null,
    delta: number,
): string | null {
    if (slugs.length === 0) {
        return null;
    }

    const index = slugs.indexOf(activeSlug ?? '');

    if (index === -1) {
        return delta > 0 ? slugs[0] : slugs[slugs.length - 1];
    }

    return slugs[(index + delta + slugs.length) % slugs.length];
}

/**
 * Group the shortcut definitions by category, preserving first-seen order, for
 * rendering in the help modal.
 */
export function shortcutsByCategory(
    shortcuts: readonly ShortcutDefinition[] = SHORTCUTS,
): { category: string; shortcuts: ShortcutDefinition[] }[] {
    const groups: { category: string; shortcuts: ShortcutDefinition[] }[] = [];

    for (const shortcut of shortcuts) {
        const group = groups.find(
            (candidate) => candidate.category === shortcut.category,
        );

        if (group) {
            group.shortcuts.push(shortcut);
        } else {
            groups.push({ category: shortcut.category, shortcuts: [shortcut] });
        }
    }

    return groups;
}
