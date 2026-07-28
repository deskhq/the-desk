import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import type { Ref } from 'vue';

export interface ChannelPinsOptions {
    /** The server's pin count for the open channel. */
    pinCount: () => number;
    /** Jump the timeline to a message, used by the panel's rows. */
    jumpToMessage: (id: string) => void;
}

export interface ChannelPins {
    /** The masthead's badge count, kept live by the MessagePinned broadcast. */
    pinCount: Ref<number>;
    pinsPanelOpen: Ref<boolean>;
    openPinsPanel: () => void;
    jumpToPin: (id: string) => void;
}

/**
 * The channel-level pin count driving the masthead badge, and the pins popover
 * state. The count is seeded from the server, resynced on partial reloads and
 * channel switches (the prop watch below), and patched live by the MessagePinned
 * broadcast; the pins list itself rides the `pins` prop, refreshed whenever the
 * panel opens or a pin changes.
 */
export function useChannelPins(options: ChannelPinsOptions): ChannelPins {
    const pinCount = ref(options.pinCount());

    watch(options.pinCount, (count) => {
        pinCount.value = count;
    });

    const pinsPanelOpen = ref(false);

    /**
     * Open the pins popover, pulling the freshest pins first — another member may
     * have pinned or unpinned since this page loaded, and the count badge and list
     * should agree with the server on open.
     */
    function openPinsPanel(): void {
        pinsPanelOpen.value = true;
        router.reload({ only: ['pins', 'pinCount'] });
    }

    /** Jump to a pinned message from the panel, closing the popover on the way. */
    function jumpToPin(id: string): void {
        pinsPanelOpen.value = false;
        options.jumpToMessage(id);
    }

    return { pinCount, pinsPanelOpen, openPinsPanel, jumpToPin };
}
