import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { Channel, Message } from '@/types';
import type { ForwardTarget } from '@/types/forward';
import type { PersonRef } from '@/types/people';

export interface ForwardDialogOptions {
    /** The actions engine's forward, run once the dialog is submitted. */
    forwardMessage: (
        source: Message,
        payload: { target: ForwardTarget; note: string },
    ) => void;
}

export interface ForwardDialog {
    /** The message being forwarded, or null when the dialog is idle. */
    forwardTarget: Ref<Message | null>;
    forwardDialogOpen: Ref<boolean>;
    /** The channels the viewer can post to, from the sidebar list. */
    forwardableChannels: ComputedRef<Channel[]>;
    /** Team members offered as DM forward targets. */
    forwardablePeople: ComputedRef<PersonRef[]>;
    openForward: (message: Message) => void;
    submitForward: (payload: { target: ForwardTarget; note: string }) => void;
}

/**
 * The forward dialog's state: which message is being forwarded, and the targets
 * offered for it. Picking a person opens-or-creates the 1:1 DM on the server.
 */
export function useForwardDialog(options: ForwardDialogOptions): ForwardDialog {
    const page = usePage();

    const forwardTarget = ref<Message | null>(null);
    const forwardDialogOpen = ref(false);

    const forwardableChannels = computed<Channel[]>(
        () => page.props.channels ?? [],
    );

    const forwardablePeople = computed<PersonRef[]>(
        () => page.props.teamMembers ?? [],
    );

    function openForward(message: Message): void {
        forwardTarget.value = message;
        forwardDialogOpen.value = true;
    }

    /**
     * Submit the forward dialog: hand the selected source and destination to the
     * actions engine, then clear the target so the dialog resets.
     */
    function submitForward(payload: {
        target: ForwardTarget;
        note: string;
    }): void {
        const source = forwardTarget.value;

        if (source) {
            options.forwardMessage(source, payload);
        }

        forwardTarget.value = null;
    }

    return {
        forwardTarget,
        forwardDialogOpen,
        forwardableChannels,
        forwardablePeople,
        openForward,
        submitForward,
    };
}
