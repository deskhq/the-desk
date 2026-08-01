export type ChannelTrafficInput = {
    /** The reply's root message id, or `null` for a top-level channel message. */
    threadRootId: string | null;
    /** A thread reply the author also chose to echo into the channel timeline. */
    sentToChannel: boolean;
};

/**
 * Whether a message is ordinary channel traffic: a top-level message, or a
 * thread reply its author explicitly also sent to the channel.
 *
 * A thread-only reply is not. It lives in the thread view, so it stays out of
 * the main timeline and out of the sidebar's unread badge, and only ever alerts
 * through the mention path — which is why the chime, the sidebar refresh and the
 * timeline placement all ask this one question rather than each re-deriving it.
 *
 * The client twin of `Message::channelTraffic()` / `Message::isChannelTraffic()`,
 * pinned against the same case table (`tests/Fixtures/channel-traffic-cases.json`)
 * so a change made on one side of the wire cannot land without the other. Its
 * counterpart in kind is {@see shouldFlagThreadUnread}, which answers the
 * *thread's* unread question; this one answers the channel's. ADR-0010 records
 * why the rule has exactly one home per language.
 */
export function isChannelTraffic(message: ChannelTrafficInput): boolean {
    return message.threadRootId === null || message.sentToChannel;
}
