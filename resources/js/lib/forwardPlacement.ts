import type { ForwardTarget } from '@/types/forward';

/** The server payload naming a forward's destination. */
export type ForwardDestination =
    { target_channel_id: string } | { target_user_id: string };

export type ForwardPlan = {
    /**
     * The forward lands back in the channel it originated from, so it renders
     * optimistically in the timeline and dedups against the broadcast echo.
     */
    toCurrentChannel: boolean;
    /** The destination payload: a channel id, or a person whose DM is opened. */
    destination: ForwardDestination;
    /**
     * The channel name to stamp on the forwarded-from quote, or `null` for a DM
     * source — matching the server so the quote reads "a direct message" rather
     * than a participant's name.
     */
    quoteChannelName: string | null;
};

/**
 * The pure decision core behind forwarding a message: whether the target is the
 * current channel (so the optimistic copy is appended locally), the destination
 * payload the request carries, and the channel name to quote the source by.
 *
 * The source always lives in the current channel — the action originates from its
 * timeline or thread — so `quoteChannelName` reflects the *current* channel, null
 * for a DM.
 */
export function planForward(input: {
    target: ForwardTarget;
    channel: { id: string; isDirect: boolean; name: string };
}): ForwardPlan {
    const { target, channel } = input;

    return {
        toCurrentChannel: target.kind === 'channel' && target.id === channel.id,
        destination:
            target.kind === 'channel'
                ? { target_channel_id: target.id }
                : { target_user_id: target.id },
        quoteChannelName: channel.isDirect ? null : channel.name,
    };
}
