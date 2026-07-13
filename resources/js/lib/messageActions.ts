import type { Message } from '@/types';

/**
 * Whether the message is an inert system notice (a member joined/left line)
 * rather than a user-authored message. System notices carry no interactions, so
 * every action guard below treats them as non-actionable, and the timeline
 * renders them as a centered line instead of a chat bubble.
 */
export function isSystemMessage(message: Pick<Message, 'type'>): boolean {
    return message.type !== 'standard';
}

/**
 * The viewer context the per-message action guards resolve against: who the
 * viewer is, what the channel lets them do, whether they're reading inside a
 * thread panel, and whether this specific row is still an in-flight optimistic
 * send. Kept as a plain shape so the guards below stay pure and unit-testable,
 * and so both `MessageList` and the extracted `MessageActions` bar can share
 * exactly the same visibility rules.
 */
export type MessageActionContext = {
    currentUserId: string;
    // Whether the viewer may add/remove reactions (member of a live channel).
    canReact: boolean;
    // Whether the viewer may moderate others' messages (delete them).
    canModerate: boolean;
    // Rendered inside a thread panel: suppresses the reply/thread affordances.
    inThread: boolean;
    // Whether this row is an optimistic send with no stable server id yet.
    pending: boolean;
};

/**
 * Whether the message belongs to the viewer, gating the author-only affordances.
 */
function isOwn(message: Message, context: MessageActionContext): boolean {
    return message.user.id === context.currentUserId;
}

/**
 * A live, confirmed row: not a tombstone and not an in-flight optimistic send.
 * Every stable-target action shares this precondition — a deleted or pending
 * message has no stable id to react to, reply to, forward, remind on, or edit.
 */
function isActionable(
    message: Message,
    context: MessageActionContext,
): boolean {
    return !message.isDeleted && !context.pending && !isSystemMessage(message);
}

/**
 * The viewer may edit only their own live message.
 */
export function canEditMessage(
    message: Message,
    context: MessageActionContext,
): boolean {
    return isActionable(message, context) && isOwn(message, context);
}

/**
 * The viewer may delete their own message, or anyone's when they can moderate.
 */
export function canDeleteMessage(
    message: Message,
    context: MessageActionContext,
): boolean {
    return (
        isActionable(message, context) &&
        (isOwn(message, context) || context.canModerate)
    );
}

/**
 * Anyone can reply to any live message; inline quoting is a main-timeline
 * affordance, so it's suppressed inside a thread panel (the composer answers the
 * root there).
 */
export function canReplyToMessage(
    message: Message,
    context: MessageActionContext,
): boolean {
    return !context.inThread && isActionable(message, context);
}

/**
 * Any live message can be forwarded to another channel — including from inside a
 * thread panel.
 */
export function canForwardMessage(
    message: Message,
    context: MessageActionContext,
): boolean {
    return isActionable(message, context);
}

/**
 * The viewer may react to any live message when they're a member of the
 * (non-archived) channel.
 */
export function canReactToMessage(
    message: Message,
    context: MessageActionContext,
): boolean {
    return context.canReact && isActionable(message, context);
}

/**
 * A reminder is personal — the viewer can set one on any live message they can
 * see, in any channel and even from inside a thread.
 */
export function canRemindAboutMessage(
    message: Message,
    context: MessageActionContext,
): boolean {
    return isActionable(message, context);
}

/**
 * The "reply in thread" action shows on live root messages in the main timeline
 * (never on replies, nor on messages already inside a thread panel).
 */
export function canStartThreadFromMessage(
    message: Message,
    context: MessageActionContext,
): boolean {
    return canReplyToMessage(message, context) && message.threadRootId === null;
}

/**
 * The "N replies" affordance shows on any root that has replies, even a deleted
 * one — the thread outlives its root as a tombstone. Not a toolbar action, but
 * it shares the same viewer context, so it lives alongside the guards.
 */
export function showsThreadSummary(
    message: Message,
    context: MessageActionContext,
): boolean {
    return (
        !context.inThread &&
        !isSystemMessage(message) &&
        message.threadReplyCount > 0
    );
}

/**
 * Whether any toolbar action is available for this message, so the hover bar can
 * skip rendering entirely when it would be empty.
 */
export function hasAnyMessageAction(
    message: Message,
    context: MessageActionContext,
): boolean {
    return (
        canReactToMessage(message, context) ||
        canReplyToMessage(message, context) ||
        canStartThreadFromMessage(message, context) ||
        canForwardMessage(message, context) ||
        canRemindAboutMessage(message, context) ||
        canEditMessage(message, context) ||
        canDeleteMessage(message, context)
    );
}
