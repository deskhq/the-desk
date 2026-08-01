import { useAutocompleteMenu } from '@/composables/useAutocompleteMenu';
import type { AutocompleteMenu } from '@/composables/useAutocompleteMenu';
import type { ComposerField } from '@/composables/useComposerField';

/**
 * A slash command occupies the whole body up to the caret: a leading `/`
 * followed by word characters and nothing else. The menu therefore triggers
 * only at composer position 0 and closes the instant a space is typed (which
 * breaks the match), matching the server's `^/(name)(\s|$)` interception rule.
 */
const SLASH_QUERY = /^\/(\w*)$/;

const MAX_SUGGESTIONS = 8;

/**
 * A command that opens a surface instead of completing to text and posting
 * through the command endpoint — `/gif` and `/poll`. Each picker module
 * declares one of these while it is available, which is what keeps the slash
 * adapter from knowing either surface exists.
 */
export type PickerCommand = {
    /** The manifest name, matched when the command is chosen from the menu. */
    name: string;
    /**
     * Whether `text`, as typed, is this command (with or without arguments),
     * and so diverts a send away from the text-command endpoint.
     */
    claims: (text: string) => boolean;
    /** Open the surface `text` asked for, clearing the command that asked. */
    open: (text: string) => void;
};

export type ComposerSlashCommands = {
    /** The `/` menu itself: rows, keyboard model, ARIA, selection. */
    menu: AutocompleteMenu<App.Data.SlashCommandData>;
    refreshSuggestions: () => void;
    looksLikeCommand: (text: string) => boolean;
    /** The picker `text` invokes, or null when it posts through the endpoint. */
    pickerFor: (text: string) => PickerCommand | null;
};

/**
 * The slash adapter over the composer's autocomplete engine: the `/name`
 * grammar, the server's manifest a query is matched against, how a chosen
 * command completes into text, and the advisory "is this a command?" check
 * that decides which endpoint a send posts to.
 */
export function useComposerSlashCommands(options: {
    field: ComposerField;
    /** The server's autocomplete manifest; empty or absent disables all slash handling. */
    commands: () => App.Data.SlashCommandData[] | undefined;
    /** The picker-backed commands currently available, consulted before completion. */
    pickers: () => PickerCommand[];
}): ComposerSlashCommands {
    const { body, caretPosition, focusRange } = options.field;

    const menu = useAutocompleteMenu<App.Data.SlashCommandData>({
        name: 'slash',
        onSelect: (command) => completeCommand(command),
    });

    function refreshSuggestions(): void {
        const commands = options.commands() ?? [];

        if (commands.length === 0) {
            menu.offer([]);

            return;
        }

        const match = body.value.slice(0, caretPosition()).match(SLASH_QUERY);

        if (!match) {
            menu.offer([]);

            return;
        }

        const needle = match[1].toLowerCase();

        menu.offer(
            commands
                .filter((command) =>
                    command.name.toLowerCase().startsWith(needle),
                )
                .slice(0, MAX_SUGGESTIONS),
        );
    }

    function completeCommand(command: App.Data.SlashCommandData): void {
        const picker = options
            .pickers()
            .find((candidate) => candidate.name === command.name);

        if (picker) {
            picker.open(`/${command.name}`);

            return;
        }

        // The menu matched on the body up to the caret, so only that prefix is
        // the typed command — whatever trails it is the rest of a half-written
        // message and has to survive the completion. The trailing space is the
        // separator, so a tail that already opens with one keeps a single space
        // rather than gaining a second.
        const tail = body.value.slice(caretPosition());
        const completed = `/${command.name} `;

        body.value = completed + (tail.startsWith(' ') ? tail.slice(1) : tail);

        focusRange(completed.length);
    }

    /**
     * Whether the trimmed body is a send-ready command the server will intercept: a
     * leading `/name` (name followed by a space or the end) matching a registered
     * command. Only advisory — it decides which endpoint the composer posts to; the
     * server re-parses authoritatively.
     */
    function looksLikeCommand(text: string): boolean {
        const match = text.match(/^\/(\S+)(?:\s|$)/);

        if (!match) {
            return false;
        }

        return (options.commands() ?? []).some(
            (command) => command.name === match[1],
        );
    }

    function pickerFor(text: string): PickerCommand | null {
        return options.pickers().find((picker) => picker.claims(text)) ?? null;
    }

    return { menu, refreshSuggestions, looksLikeCommand, pickerFor };
}
