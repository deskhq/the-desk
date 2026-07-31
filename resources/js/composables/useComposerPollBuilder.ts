import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { ComposerField } from '@/composables/useComposerField';
import type { PickerCommand } from '@/composables/useComposerSlashCommands';

/** The `/poll` command, whose arguments the builder does not read. */
const POLL_COMMAND = /^\/poll(?:\s+.*)?$/i;

export type ComposerPollBuilder = {
    /** Whether the builder is up. */
    open: Ref<boolean>;
    /** Whether polls are enabled and the builder applies to this composer. */
    available: ComputedRef<boolean>;
    /** The `/poll` command, or null while the builder is unavailable. */
    command: ComputedRef<PickerCommand | null>;
    /** Open the builder, leaving the body alone. */
    openBuilder: () => void;
    close: () => void;
};

/**
 * The composer's poll builder: when `/poll` opens it rather than posting text.
 * The composed poll is posted as a first-class poll message through its own
 * endpoint, so nothing of it comes back through the composer.
 */
export function useComposerPollBuilder(options: {
    field: ComposerField;
    /** Whether the poll builder is enabled for this instance. */
    pollsEnabled: () => boolean;
    teamSlug: () => string | undefined;
    channelSlug: () => string | undefined;
    /** Whether an existing message is being edited (the builder does not apply then). */
    isEditing: () => boolean;
    /** Dismiss the slash menu, which the builder opens over. */
    closeSlashMenu: () => void;
}): ComposerPollBuilder {
    const { body, focus } = options.field;

    const open = ref(false);

    /**
     * The builder is usable only when polls are enabled, when the composer knows its
     * channel (the poll is posted to that channel), and while composing a new
     * message — not editing an existing one.
     */
    const available = computed(
        () =>
            options.pollsEnabled() &&
            Boolean(options.teamSlug()) &&
            Boolean(options.channelSlug()) &&
            !options.isEditing(),
    );

    /** Open the builder, leaving the body alone for the same reason `/gif` does. */
    function openBuilder(): void {
        options.closeSlashMenu();
        open.value = true;
    }

    /** The `/poll` command path: the typed command goes once the builder is up. */
    function openFromCommand(): void {
        openBuilder();
        body.value = '';
    }

    const command = computed<PickerCommand | null>(() =>
        available.value
            ? {
                  name: 'poll',
                  claims: (text) => POLL_COMMAND.test(text),
                  open: openFromCommand,
              }
            : null,
    );

    function close(): void {
        open.value = false;
        focus();
    }

    return { open, available, command, openBuilder, close };
}
