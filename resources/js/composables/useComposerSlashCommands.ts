import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { ComposerField } from '@/composables/useComposerField';
import type { AttachmentData } from '@/types/attachments';

/**
 * A slash command occupies the whole body up to the caret: a leading `/`
 * followed by word characters and nothing else. The menu therefore triggers
 * only at composer position 0 and closes the instant a space is typed (which
 * breaks the match), matching the server's `^/(name)(\s|$)` interception rule.
 */
const SLASH_QUERY = /^\/(\w*)$/;

const MAX_SUGGESTIONS = 8;

/**
 * The one picker-backed command: `/gif` opens the Giphy picker instead of
 * completing to text and posting through the command endpoint.
 */
const GIF_COMMAND_NAME = 'gif';

/**
 * The other picker-backed command: `/poll` opens the poll builder instead of
 * completing to text and posting through the command endpoint.
 */
const POLL_COMMAND_NAME = 'poll';

export type ComposerSlashCommands = {
    slashSuggestions: Ref<App.Data.SlashCommandData[]>;
    slashActiveIndex: Ref<number>;
    slashMenuOpen: Ref<boolean>;
    showSlashMenu: ComputedRef<boolean>;
    refreshSlashSuggestions: () => void;
    slashMoveActive: (delta: number) => void;
    selectSlashCommand: (command: App.Data.SlashCommandData) => void;
    selectSlashActive: () => void;
    closeSlashMenu: () => void;
    looksLikeCommand: (text: string) => boolean;
    gifPickerOpen: Ref<boolean>;
    gifPickerQuery: Ref<string>;
    gifPickerAvailable: ComputedRef<boolean>;
    gifCommandQuery: (text: string) => string | null;
    openGifPicker: (query: string) => void;
    closeGifPicker: () => void;
    onGifSelected: (attachment: AttachmentData) => void;
    pollComposerOpen: Ref<boolean>;
    pollComposerAvailable: ComputedRef<boolean>;
    isPollCommand: (text: string) => boolean;
    openPollComposer: () => void;
    closePollComposer: () => void;
};

/**
 * The composer's slash-command surface: the autocomplete menu, the advisory
 * "is this a command?" check that decides which endpoint a send posts to, and
 * the two commands that open a picker rather than posting text at all.
 */
export function useComposerSlashCommands(options: {
    field: ComposerField;
    /** The server's autocomplete manifest; empty or absent disables all slash handling. */
    commands: () => App.Data.SlashCommandData[] | undefined;
    /** Whether the Giphy picker is configured for this instance. */
    gifPickerEnabled: () => boolean;
    /** Whether the poll builder is enabled for this instance. */
    pollsEnabled: () => boolean;
    /** Whether the composer knows its channel, so a picked GIF can be staged. */
    attachmentsEnabled: () => boolean;
    teamSlug: () => string | undefined;
    channelSlug: () => string | undefined;
    /** Whether an existing message is being edited (neither picker applies then). */
    isEditing: () => boolean;
    /** Stage a picked GIF in the attachment tray; false when the tray refused it. */
    stageRemote: (attachment: AttachmentData) => boolean;
}): ComposerSlashCommands {
    const { body, caretPosition, focus, focusRange } = options.field;

    const slashSuggestions = ref<App.Data.SlashCommandData[]>([]);
    const slashActiveIndex = ref(0);
    const slashMenuOpen = ref(false);

    const showSlashMenu = computed(
        () => slashMenuOpen.value && slashSuggestions.value.length > 0,
    );

    /** The GIF picker's open state and the search term it opens on (from `/gif cats`). */
    const gifPickerOpen = ref(false);
    const gifPickerQuery = ref('');

    /** The poll builder's open state. */
    const pollComposerOpen = ref(false);

    function refreshSlashSuggestions(): void {
        const commands = options.commands() ?? [];

        if (commands.length === 0) {
            slashMenuOpen.value = false;
            slashSuggestions.value = [];

            return;
        }

        const match = body.value.slice(0, caretPosition()).match(SLASH_QUERY);

        if (!match) {
            slashMenuOpen.value = false;
            slashSuggestions.value = [];

            return;
        }

        const needle = match[1].toLowerCase();

        slashSuggestions.value = commands
            .filter((command) => command.name.toLowerCase().startsWith(needle))
            .slice(0, MAX_SUGGESTIONS);
        slashActiveIndex.value = 0;
        slashMenuOpen.value = slashSuggestions.value.length > 0;
    }

    function slashMoveActive(delta: number): void {
        const count = slashSuggestions.value.length;
        slashActiveIndex.value =
            (slashActiveIndex.value + delta + count) % count;
    }

    function closeSlashMenu(): void {
        slashMenuOpen.value = false;
    }

    /**
     * The picker is usable only when configured, when the composer knows its
     * channel (a picked GIF is staged as an attachment on that channel), and while
     * composing a new message — not editing an existing one (an inline edit saves
     * text only and cannot carry an attachment).
     */
    const gifPickerAvailable = computed(
        () =>
            options.gifPickerEnabled() &&
            options.attachmentsEnabled() &&
            !options.isEditing(),
    );

    /**
     * The search term if `text` is the `/gif` command (`/gif` or `/gif <query>`) and
     * the picker is available, else null. Used to divert `/gif` away from the text
     * command path and into the picker.
     */
    function gifCommandQuery(text: string): string | null {
        if (!gifPickerAvailable.value) {
            return null;
        }

        const match = text.match(/^\/gif(?:\s+(.*))?$/i);

        return match ? (match[1]?.trim() ?? '') : null;
    }

    /** Open the GIF picker on the given search term, clearing the `/gif` text. */
    function openGifPicker(query: string): void {
        slashMenuOpen.value = false;
        gifPickerQuery.value = query;
        gifPickerOpen.value = true;
        body.value = '';
    }

    function closeGifPicker(): void {
        gifPickerOpen.value = false;
        focus();
    }

    /**
     * A picked GIF joins the tray as a remote attachment; the picker closes only if
     * it was accepted, so a full tray keeps the picker open with its toast shown.
     */
    function onGifSelected(attachment: AttachmentData): void {
        if (options.stageRemote(attachment)) {
            closeGifPicker();
        }
    }

    /**
     * The builder is usable only when polls are enabled, when the composer knows its
     * channel (the poll is posted to that channel), and while composing a new
     * message — not editing an existing one.
     */
    const pollComposerAvailable = computed(
        () =>
            options.pollsEnabled() &&
            Boolean(options.teamSlug()) &&
            Boolean(options.channelSlug()) &&
            !options.isEditing(),
    );

    /** Whether `text` is the `/poll` command and the builder is available. */
    function isPollCommand(text: string): boolean {
        return pollComposerAvailable.value && /^\/poll(?:\s+.*)?$/i.test(text);
    }

    /** Open the poll builder, closing the slash menu and clearing the `/poll` text. */
    function openPollComposer(): void {
        slashMenuOpen.value = false;
        pollComposerOpen.value = true;
        body.value = '';
    }

    function closePollComposer(): void {
        pollComposerOpen.value = false;
        focus();
    }

    function selectSlashCommand(command: App.Data.SlashCommandData): void {
        if (command.name === GIF_COMMAND_NAME && gifPickerAvailable.value) {
            openGifPicker('');

            return;
        }

        if (command.name === POLL_COMMAND_NAME && pollComposerAvailable.value) {
            openPollComposer();

            return;
        }

        body.value = `/${command.name} `;
        slashMenuOpen.value = false;

        focusRange(body.value.length);
    }

    function selectSlashActive(): void {
        const command = slashSuggestions.value[slashActiveIndex.value];

        if (command) {
            selectSlashCommand(command);
        }
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

    return {
        slashSuggestions,
        slashActiveIndex,
        slashMenuOpen,
        showSlashMenu,
        refreshSlashSuggestions,
        slashMoveActive,
        selectSlashCommand,
        selectSlashActive,
        closeSlashMenu,
        looksLikeCommand,
        gifPickerOpen,
        gifPickerQuery,
        gifPickerAvailable,
        gifCommandQuery,
        openGifPicker,
        closeGifPicker,
        onGifSelected,
        pollComposerOpen,
        pollComposerAvailable,
        isPollCommand,
        openPollComposer,
        closePollComposer,
    };
}
