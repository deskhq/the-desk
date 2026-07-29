import { computed, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { useLongPress } from '@/composables/useLongPress';
import type { UseLongPress } from '@/composables/useLongPress';
import type { Message } from '@/types';

export type MessageActionSheet = {
    /** Whether the sheet is up. */
    open: Ref<boolean>;
    /** The message it is open on, resolved live so its state stays current. */
    message: ComputedRef<Message | null>;
    /** The gesture that opens it, bound to every message row's pointer events. */
    longPress: UseLongPress<Message>;
    /** Whether a given row is the one under a live press, for its hold cue. */
    isHeld: (message: Message) => boolean;
};

/**
 * The touch stand-in for the hover toolbar (design m4): below `md`, pressing
 * and holding a message row opens its actions bottom sheet. The sheet resolves
 * the message live by id, so reaction and pin state stay current while it is
 * open, and the id survives the close animation.
 */
export function useMessageActionSheet(options: {
    /** Whether the gesture is live at all (below the `md` breakpoint). */
    isMobile: Ref<boolean>;
    /** The timeline's messages, which the open sheet resolves its id against. */
    messages: () => Message[];
    /** Whether a row has any action to offer; a row with none never opens. */
    canOpen: (message: Message) => boolean;
}): MessageActionSheet {
    const actionSheetId = ref<string | null>(null);
    const open = ref(false);

    const message = computed(
        () =>
            options
                .messages()
                .find((entry) => entry.id === actionSheetId.value) ?? null,
    );

    function openActionsSheet(pressed: Message): void {
        if (!options.canOpen(pressed)) {
            return;
        }

        actionSheetId.value = pressed.id;
        open.value = true;
    }

    const longPress = useLongPress<Message>({
        enabled: options.isMobile,
        onLongPress: openActionsSheet,
    });

    /** The pressed row's hold cue while the long-press timer runs. */
    function isHeld(row: Message): boolean {
        return longPress.pressing.value?.id === row.id;
    }

    // Crossing up over the breakpoint mid-press leaves the sheet stranded on a
    // hover-capable layout; the toolbar owns the actions there.
    watch(options.isMobile, (mobile) => {
        if (!mobile) {
            open.value = false;
        }
    });

    // A message deleted (or pruned from the window) while its sheet is up has no
    // actions left to offer. Watched through a getter so an in-place tombstone
    // patch from a broadcast closes it too, not only a replaced array entry.
    watch(
        () => message.value?.isDeleted,
        (isDeleted) => {
            if (isDeleted !== false) {
                open.value = false;
            }
        },
    );

    return { open, message, longPress, isHeld };
}
