// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { visit, destroy, put } = vi.hoisted(() => ({
    visit: vi.fn(),
    destroy: vi.fn(),
    put: vi.fn(),
}));

/**
 * Hoisted, all of it: the registry calls its composables at
 * module-*evaluation* time, so a plain `const` here would still be in its
 * temporal dead zone by the time a mock factory is first asked for it.
 */
const page = vi.hoisted(() => ({
    url: '/t/acme/c/general',
    props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { visit, delete: destroy, put, flushAll: vi.fn() },
    usePage: () => page,
}));

const { pinUrl } = vi.hoisted(() => ({ pinUrl: vi.fn() }));

vi.mock('@/lib/pinUrl', () => ({ pinUrl }));

import { browse } from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { destroy as destroyDndPause } from '@/actions/App/Http/Controllers/Settings/DndController';
import { update as updatePresence } from '@/actions/App/Http/Controllers/Settings/PresenceController';
import { COMMANDS } from '@/composables/commands';
import { SHORTCUTS } from '@/composables/keyboardShortcuts';
import { SHELL_DIALOG_NAMES, useDialog } from '@/composables/useDialog';
import type { ShellDialog } from '@/composables/useDialog';

/** A workspace the viewer is standing in, with the invite permission. */
function seed(props: Record<string, unknown> = {}): void {
    page.props = {
        currentTeam: { id: 't1', slug: 'acme' },
        canInviteToCurrentTeam: true,
        creatableChannelVisibilities: ['public', 'private'],
        auth: { user: { dnd: null, timezone: 'UTC' } },
        ...props,
    };
}

/** Run the command with this id, failing loudly when nothing carries it. */
function run(id: string): void {
    const command = COMMANDS.find((candidate) => candidate.id === id);

    expect(command, `no command is registered as "${id}"`).toBeDefined();

    command?.run();
}

/** jsdom has no `matchMedia`; the "system" theme resolves through it. */
function stubMatchMedia(): void {
    window.matchMedia = ((query: string) => ({
        matches: false,
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
    })) as unknown as typeof window.matchMedia;
}

beforeEach(() => {
    vi.clearAllMocks();
    stubMatchMedia();
    localStorage.clear();
    page.url = '/t/acme/c/general';
    seed();
});

describe('the derivation guard', () => {
    it('has exactly one command claiming every dispatchable shortcut', () => {
        const dispatchable = SHORTCUTS.filter(
            (shortcut) => !shortcut.displayOnly,
        ).map((shortcut) => shortcut.id);

        for (const id of dispatchable) {
            expect(
                COMMANDS.filter((command) => command.shortcutId === id),
            ).toHaveLength(1);
        }
    });

    it('claims no shortcut the dispatcher never fires', () => {
        const displayOnly = SHORTCUTS.filter(
            (shortcut) => shortcut.displayOnly,
        ).map((shortcut) => shortcut.id);

        for (const command of COMMANDS) {
            expect(displayOnly).not.toContain(command.shortcutId);
        }
    });

    it('gives every command its own id', () => {
        const ids = COMMANDS.map((command) => command.id);

        expect(new Set(ids).size).toBe(ids.length);
    });
});

describe('the theme trio', () => {
    it.each([
        ['theme-light', 'light'],
        ['theme-dark', 'dark'],
        ['theme-system', 'system'],
    ])('%s asks for the %s appearance', (id, expected) => {
        run(id);

        expect(localStorage.getItem('appearance')).toBe(expected);
    });
});

/**
 * Which of the shell's dialogs each dialog-opening command reaches for. Five
 * entries or nine, the assertion below is the same one: the key names a real
 * {@link ShellDialog} and running the command flips its singleton. `useDialog`
 * is the genuine article here rather than a mock, as it is in
 * `useShellShortcuts.test.ts` — the singleton is the observable effect.
 */
const DIALOG_COMMANDS: Record<string, ShellDialog> = {
    'command-palette': 'switcher',
    'show-shortcuts': 'shortcuts',
    'new-message': 'newMessage',
    'create-channel': 'createChannel',
    'set-status': 'status',
    'pause-notifications': 'dnd',
    'invite-people': 'invite',
};

describe('the dialog verbs', () => {
    it.each(Object.entries(DIALOG_COMMANDS))(
        '%s opens the %s dialog',
        (id, dialog) => {
            for (const name of SHELL_DIALOG_NAMES) {
                useDialog(name).close();
            }

            run(id);

            expect(useDialog(dialog).isOpen.value).toBe(true);
        },
    );
});

/**
 * The two dock verbs write `?nav=` onto the current route and let the dock's own
 * watcher adopt it — the same channel a deep link uses. They must *not* reach
 * for `useNavPanel()`, whose state is per-instance: a module-scope call would
 * drive a panel nobody renders, and it would type-check.
 */
describe('the dock verbs', () => {
    it.each([
        ['reminders', 'reminders'],
        ['search', 'search'],
    ])('%s pins its destination on the current route', (id, destination) => {
        run(id);

        expect(pinUrl).toHaveBeenCalledWith(
            `/t/acme/c/general?nav=${destination}`,
        );
    });

    it('keeps the query params the route already carries', () => {
        page.url = '/t/acme/c/general?thread=m1';

        run('search');

        expect(pinUrl).toHaveBeenCalledWith(
            '/t/acme/c/general?thread=m1&nav=search',
        );
    });
});

describe('browse-channels', () => {
    it('visits the current workspace’s browse route', () => {
        run('browse-channels');

        expect(visit).toHaveBeenCalledWith(browse('acme').url);
    });
});

/**
 * Routed through {@see useUserMenu}, which already owns this call *and* its
 * error toast — which is what lets `run` be fire-and-forget without
 * `CommandDefinition` growing a result channel.
 */
describe('resume-notifications', () => {
    it('ends the running pause', () => {
        run('resume-notifications');

        expect(destroy).toHaveBeenCalledWith(
            destroyDndPause().url,
            expect.anything(),
        );
    });

    it('is offered only while do-not-disturb is actually running', () => {
        const command = COMMANDS.find(
            (candidate) => candidate.id === 'resume-notifications',
        );

        expect(command?.isAvailable?.()).toBe(false);

        seed({
            auth: {
                user: {
                    dnd: { until: '2999-01-01T00:00:00Z' },
                    timezone: 'UTC',
                },
            },
        });

        expect(command?.isAvailable?.()).toBe(true);
    });
});

/**
 * One capability as two gated rows rather than one row whose label follows the
 * viewer's presence (#1224). The pair is observationally identical to a dynamic
 * title — exactly one of them is ever offered — which is why the contract was
 * left alone, so the assertion that matters is that "exactly one" holds in every
 * state rather than that either row exists.
 */
describe('the presence rows', () => {
    /** The ids of the presence rows this viewer is actually offered. */
    function offeredPresenceRows(): string[] {
        return COMMANDS.filter(
            (command) =>
                command.id.startsWith('set-presence-') &&
                (command.isAvailable?.() ?? true),
        ).map((command) => command.id);
    }

    it.each([
        ['set-presence-away', 'away'],
        ['set-presence-active', 'active'],
    ])('%s asks the server for %s outright', (id, state) => {
        run(id);

        expect(put).toHaveBeenCalledWith(
            updatePresence().url,
            { state },
            expect.anything(),
        );
    });

    /**
     * Including the case where the prop is missing altogether: a viewer reading
     * the palette is plainly here, so the shell reads an absent presence as
     * active exactly as the user menu does, and still offers them a row.
     */
    it.each([
        [undefined, 'set-presence-away'],
        ['active', 'set-presence-away'],
        ['away', 'set-presence-active'],
    ])(
        'offers exactly one row while presence reads %s',
        (presence, offered) => {
            seed({ auth: { user: { dnd: null, timezone: 'UTC', presence } } });

            expect(offeredPresenceRows()).toEqual([offered]);
        },
    );
});

/**
 * Read in both polarities, because the failure this guards against is the whole
 * gate reading `undefined`: a predicate pointed at the wrong prop path hides the
 * row from everyone, and a one-sided assertion cannot tell that apart from the
 * policy doing its job.
 */
describe('create-channel', () => {
    it('is offered only where the workspace policy leaves a visibility open', () => {
        const command = COMMANDS.find(
            (candidate) => candidate.id === 'create-channel',
        );

        expect(command?.isAvailable?.()).toBe(true);

        seed({ creatableChannelVisibilities: [] });

        expect(command?.isAvailable?.()).toBe(false);
    });
});

/**
 * A predicate that reaches one level too deep (`currentTeam.slug` where
 * `currentTeam` was meant) does not quietly hide its own row — it throws inside
 * the computed that builds the list, and takes the whole palette's render with
 * it. So every predicate has to survive a page carrying nothing.
 */
describe('availability on a bare page', () => {
    it('answers false rather than raising', () => {
        page.props = {};

        for (const command of COMMANDS) {
            expect(
                () => command.isAvailable?.(),
                `${command.id} raised on an empty page`,
            ).not.toThrow();

            expect(command.isAvailable?.() ?? false).toBe(false);
        }
    });
});

/**
 * The strongest assertion here, and the one that costs nothing when verb
 * seventeen lands. Because the registry is module-scope, *importing the file is
 * what calls the composables* — so a spy around the import catches the entire
 * silent-cliff class at once, for every entry ever added, rather than one entry
 * at a time. A `run` closing over something that only works inside a component
 * instance (an `onMounted`, an `inject`) warns here instead of at 3am in a
 * console nobody is reading.
 */
describe('module-scope hygiene', () => {
    it('emits no Vue warning when the registry is imported', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        vi.resetModules();
        await import('@/composables/commands');

        expect(warn).not.toHaveBeenCalled();

        warn.mockRestore();
    });
});
