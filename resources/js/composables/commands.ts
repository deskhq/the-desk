import { router, usePage } from '@inertiajs/vue3';
import {
    AlarmClock,
    ArrowDown,
    ArrowUp,
    Bell,
    BellOff,
    BellRing,
    Circle,
    CircleDot,
    Hash,
    Keyboard,
    Monitor,
    Moon,
    Plus,
    Search,
    SmilePlus,
    SquarePen,
    Sun,
    UserPlus,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import {
    browse,
    show,
} from '@/actions/App/Http/Controllers/Channels/ChannelController';
import { adjacentSlug, SHORTCUTS } from '@/composables/keyboardShortcuts';
import type { ShortcutId } from '@/composables/keyboardShortcuts';
import { scoreChannelName } from '@/composables/quickSwitcher';
import { useAppearance } from '@/composables/useAppearance';
import { useDialog } from '@/composables/useDialog';
import { urlForDestination } from '@/composables/useNavPanel';
import { useShellFocus } from '@/composables/useShellFocus';
import { useUserMenu } from '@/composables/useUserMenu';
import { canCreateChannel } from '@/lib/channelCreation';
import { isDndActiveNow } from '@/lib/dnd';
import { translate } from '@/lib/i18n';
import { pinUrl } from '@/lib/pinUrl';

/**
 * How a command names itself. A command that claims a keyboard shortcut
 * inherits that shortcut's description and renders its keys by lookup, so the
 * union is what stops a label from growing a second home: the compiler refuses
 * a `title` beside a `shortcutId`.
 */
type CommandNaming =
    | { shortcutId: ShortcutId; title?: never }
    | { title: string; shortcutId?: never };

/**
 * Whether a command is a row in the palette. Everything is, bar the one command
 * that opens the palette — which must never be a row inside it, and so carries
 * no icon either. The mirror of {@see ShortcutDefinition.displayOnly}: that is
 * "listed in the help modal but never dispatched globally", this is "dispatched
 * globally but never listed in the palette".
 */
type CommandPresence =
    { icon: LucideIcon; hidden?: never } | { hidden: true; icon?: never };

export type CommandDefinition = {
    /** Unique, untranslated. The row's `value` and its `data-test` suffix. */
    id: string;
    /**
     * Whether to offer this here and now, covering permission and applicability
     * alike. Synchronous and reading only state already on the wire: the list
     * highlights its first row on every keystroke, so a row that popped in a
     * beat late would change what `Enter` runs under the user's fingers.
     */
    isAvailable?: () => boolean;
    run: () => void;
} & CommandNaming &
    CommandPresence;

const page = usePage();
const { focusNotificationRail } = useShellFocus();
const { updateAppearance } = useAppearance();
const { resumeNotifications, setPresence } = useUserMenu();

/**
 * Jump `delta` channels along the sidebar list from the active one, wrapping at
 * either end. Does nothing until a team and its channels are loaded.
 */
function moveChannel(delta: number): void {
    const team = page.props.currentTeam;

    if (!team) {
        return;
    }

    const activeSlug =
        (page.props.channel as { slug?: string } | undefined)?.slug ?? null;

    const slug = adjacentSlug(
        (page.props.channels ?? []).map((channel) => channel.slug),
        activeSlug,
        delta,
    );

    if (slug) {
        router.visit(show({ team: team.slug, channel: slug }).url);
    }
}

/**
 * The presence the viewer would be moving *to*, or null when the page carries
 * nobody to move. The same derivation the user menu's own toggle makes, read
 * straight off the shared prop rather than through its `computed`: a predicate
 * is called inside the palette's own `computed` on every rebuild, and a cached
 * reading would offer the row for a presence the viewer has since left.
 *
 * An absent presence reads as active and never as offline, because someone
 * reading the palette is plainly here.
 */
function presenceTogglesTo(): App.Enums.PresenceState | null {
    const user = page.props.auth?.user;

    if (user === undefined) {
        return null;
    }

    return (user.presence ?? 'active') === 'away' ? 'active' : 'away';
}

/**
 * Everything the shell can be asked to do, authored once.
 *
 * Static and module-scope, which is the property that matters: a test can
 * import and walk this array without mounting anything, so the guarantee that
 * no verb is half-wired is total rather than sampled. Every `run` closes over a
 * module singleton — `useDialog`, `usePage`, `useShellFocus`, `router`,
 * `pinUrl` — all of which work outside a component instance.
 *
 * The cost of that, recorded so it is not rediscovered as a bug: a `run` calls
 * its composable at *module-evaluation* time, so a verb needing genuinely
 * per-component state has no way in and would fail silently. Such a verb is not
 * a command; it is a component's own affair. `commands.test.ts` spies on Vue's
 * warnings around this module's import to make that cliff loud.
 */
export const COMMANDS: readonly CommandDefinition[] = [
    {
        id: 'command-palette',
        shortcutId: 'command-palette',
        hidden: true,
        run: () => useDialog('switcher').toggle(),
    },
    {
        id: 'previous-channel',
        shortcutId: 'previous-channel',
        icon: ArrowUp,
        run: () => moveChannel(-1),
    },
    {
        id: 'next-channel',
        shortcutId: 'next-channel',
        icon: ArrowDown,
        run: () => moveChannel(1),
    },
    {
        id: 'focus-notifications',
        shortcutId: 'focus-notifications',
        icon: Bell,
        run: () => focusNotificationRail(),
    },
    {
        id: 'show-shortcuts',
        shortcutId: 'show-shortcuts',
        icon: Keyboard,
        run: () => useDialog('shortcuts').toggle(),
    },
    {
        id: 'new-message',
        title: 'New message',
        icon: SquarePen,
        isAvailable: () => Boolean(page.props.currentTeam),
        run: () => useDialog('newMessage').open(),
    },
    {
        /**
         * Gated on the workspace's channel-creation policy, the same reading
         * every other affordance that opens this dialog makes — and the reason
         * the verb waited for the dialog to become a shell singleton (#1223):
         * as a wrapper around its own triggers there was no ref to flip.
         */
        id: 'create-channel',
        title: 'Create a channel',
        icon: Plus,
        isAvailable: () =>
            canCreateChannel(page.props.creatableChannelVisibilities),
        run: () => useDialog('createChannel').open(),
    },
    {
        id: 'browse-channels',
        title: 'Browse channels',
        icon: Hash,
        isAvailable: () => Boolean(page.props.currentTeam),
        run: () => {
            const team = page.props.currentTeam;

            if (team) {
                router.visit(browse(team.slug).url);
            }
        },
    },
    {
        /**
         * Writes the URL and lets the dock's own `watch(() => page.url)` adopt
         * it, which is the channel a deep link already uses. Calling
         * `useNavPanel()` here would type-check and do nothing: it hands back
         * per-instance state, so a module-scope call drives a panel that is not
         * on screen.
         */
        id: 'reminders',
        title: 'Reminders',
        icon: AlarmClock,
        run: () => pinUrl(urlForDestination(page.url, 'reminders')),
    },
    {
        id: 'search',
        title: 'Search',
        icon: Search,
        run: () => pinUrl(urlForDestination(page.url, 'search')),
    },
    {
        id: 'set-status',
        title: 'Set a status',
        icon: SmilePlus,
        run: () => useDialog('status').open(),
    },
    {
        /**
         * One toggle as two gated rows, and the answer to the first verb this
         * contract could not express (#1224). A single row would have to name
         * the state it moves to, which is a `title` that follows state, and
         * {@link CommandDefinition} keeps that string static on purpose. The
         * pair is what the widening would have rendered anyway — exactly one of
         * these is ever offered — so the catalogue names the states instead,
         * the same answer the theme trio gave.
         *
         * The glyphs quote {@see PresenceDot}, which draws away as a hollow
         * ring and active as a filled disc. They are near-identical shapes,
         * which is safe only because the two rows are mutually exclusive.
         */
        id: 'set-presence-away',
        title: 'Set yourself away',
        icon: Circle,
        isAvailable: () => presenceTogglesTo() === 'away',
        run: () => setPresence('away'),
    },
    {
        id: 'set-presence-active',
        title: 'Set yourself active',
        icon: CircleDot,
        isAvailable: () => presenceTogglesTo() === 'active',
        run: () => setPresence('active'),
    },
    {
        id: 'pause-notifications',
        title: 'Pause notifications',
        icon: BellOff,
        run: () => useDialog('dnd').open(),
    },
    {
        /**
         * Through the user menu, which already owns this call *and* the error
         * toast it raises — which is what lets `run` be fire-and-forget without
         * {@link CommandDefinition} growing a result channel.
         *
         * The mutation the catalogue carries is this one rather than *Log out*:
         * the list highlights its first row as you type, so a mutating verb has
         * to be cheap to be wrong about. An accidental resume is undone by the
         * *Pause notifications* row in the same list; a sign-out is not.
         */
        id: 'resume-notifications',
        title: 'Resume notifications',
        icon: BellRing,
        isAvailable: () =>
            isDndActiveNow(
                page.props.auth?.user.dnd,
                page.props.auth?.user.timezone,
            ),
        run: () => resumeNotifications(),
    },
    {
        /**
         * Gated because the modal is only *mounted* for a viewer who may
         * invite ({@see DialogHost}), so an ungated command would flip a ref
         * with nothing listening — a silent no-op rather than a refusal.
         */
        id: 'invite-people',
        title: 'Invite people',
        icon: UserPlus,
        isAvailable: () => page.props.canInviteToCurrentTeam === true,
        run: () => useDialog('invite').open(),
    },
    {
        id: 'theme-light',
        title: 'Use light theme',
        icon: Sun,
        run: () => updateAppearance('light'),
    },
    {
        id: 'theme-dark',
        title: 'Use dark theme',
        icon: Moon,
        run: () => updateAppearance('dark'),
    },
    {
        id: 'theme-system',
        title: 'Match system theme',
        icon: Monitor,
        run: () => updateAppearance('system'),
    },
];

/** A command as the palette draws it, with everything it renders resolved. */
export type PaletteCommand = {
    id: string;
    /** Translated, and inherited from the claimed shortcut where there is one. */
    title: string;
    icon: LucideIcon;
    /** The claimed shortcut's display keys; empty for a command claiming none. */
    keys: string[];
    run: () => void;
};

/**
 * The English source of a command's title. A command claiming a shortcut has no
 * title of its own: it inherits the binding table's one string, which is what
 * stops a label from growing a second home.
 */
function sourceTitle(command: CommandDefinition): string {
    if (command.shortcutId === undefined) {
        return command.title;
    }

    return (
        SHORTCUTS.find((shortcut) => shortcut.id === command.shortcutId)
            ?.description ?? command.id
    );
}

/** A command's row, or null for the one command that is never a row. */
function paletteRow(command: CommandDefinition): PaletteCommand | null {
    if (command.hidden === true) {
        return null;
    }

    return {
        id: command.id,
        title: translate(sourceTitle(command)),
        icon: command.icon,
        keys:
            SHORTCUTS.find((shortcut) => shortcut.id === command.shortcutId)
                ?.keys ?? [],
        run: command.run,
    };
}

/**
 * Filter and rank the catalogue for the palette's Commands group: what the
 * viewer may run here and now, matching `query`, best match first.
 *
 * Titles are scored translated, so the list answers to the language it is read
 * in, on the same scorer the channel and people groups already rank by — and on
 * the trimmed query they rank on rather than the residual text the message
 * search uses. An empty query scores every row `0`, leaving the registry's own
 * order, which is the order the open palette shows.
 *
 * The predicates are called *here*, inside the caller's `computed`, rather than
 * once at module scope: availability is a live reading, and a frozen one would
 * offer a verb whose target has since gone.
 */
export function rankCommands(query: string): PaletteCommand[] {
    return COMMANDS.filter((command) => command.isAvailable?.() ?? true)
        .map(paletteRow)
        .filter((row): row is PaletteCommand => row !== null)
        .map((row) => ({ row, score: scoreChannelName(row.title, query) }))
        .filter(
            (scored): scored is { row: PaletteCommand; score: number } =>
                scored.score !== null,
        )
        .sort((a, b) => b.score - a.score)
        .map((scored) => scored.row);
}

/**
 * The dispatcher's handler map, derived from {@link COMMANDS} in full — there
 * is no literal map beside it to drift away from. A claimed command's `run`
 * *is* the shortcut's handler, so the keyboard and the palette invoke the one
 * function.
 */
export const SHORTCUT_HANDLERS: Partial<Record<ShortcutId, () => void>> =
    Object.fromEntries(
        COMMANDS.filter((command) => command.shortcutId !== undefined).map(
            (command) => [command.shortcutId, command.run],
        ),
    );
