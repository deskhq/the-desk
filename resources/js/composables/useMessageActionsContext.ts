import type { InjectionKey } from 'vue';
import { inject, provide } from 'vue';
import type { MessageActionContext } from '@/lib/messageActions';
import type { Message } from '@/types';

/**
 * Every action a message row can ask for, each taking the message (or its id) it
 * acts on: the eleven writes, plus `reply` and `openThread`, which move the
 * reader rather than writing but rode the identical relay. Declared once here
 * and provided once by the channel page, so no module between the page and the
 * row re-declares them (ADR-0009).
 *
 * `edit` and `delete` are the writes themselves, not the affordances that lead
 * to them: opening the inline editor and raising the delete confirmation are
 * timeline-local UI, which `MessageList` owns and reaches through its own
 * `startEdit` / `requestDelete` events.
 */
export interface MessageActionHandlers {
    react: (message: Message, emoji: string) => void;
    vote: (message: Message, optionId: string) => void;
    closePoll: (message: Message) => void;
    pin: (message: Message) => void;
    unpin: (message: Message) => void;
    remind: (message: Message, remindAt: string) => void;
    remindCustom: (message: Message) => void;
    forward: (message: Message) => void;
    jump: (messageId: string) => void;
    edit: (message: Message, body: string) => void;
    delete: (message: Message) => void;
    reply: (message: Message) => void;
    openThread: (messageId: string) => void;
}

/**
 * The viewer-and-channel scope: who is reading, what the open channel lets them
 * do, and the actions they can ask for. Provided once by the channel page and
 * read by every descendant that renders a message.
 */
export interface MessageActionsContext {
    currentUserId: string;
    /** Whether the viewer may add/remove reactions (member of a live channel). */
    canReact: boolean;
    /**
     * Whether the viewer may pin/unpin messages (member of a non-archived
     * channel). Pinning is a shared toggle — any member may unpin any pin.
     */
    canPin: boolean;
    /** Whether the viewer may moderate others' messages (delete them). */
    canModerate: boolean;
    /**
     * The viewer's stored zone, rendering every timestamp and feeding the
     * reminder popover's wall-clock presets; null when they have none stored,
     * which reads as the browser's own zone.
     */
    viewerTimeZone: string | null;
    actions: MessageActionHandlers;
}

/**
 * The scope one timeline owns rather than the page: the main pane and the
 * thread panel each render the same rows, but answer these two differently.
 */
export interface MessageSubtreeContext {
    /** Rendered inside a thread panel: suppresses the reply/thread affordances. */
    inThread: boolean;
    /** Drop a mention into the composer this subtree replies through. */
    mention: (member: { id: string; name: string }) => void;
}

const messageActionsKey: InjectionKey<MessageActionsContext> =
    Symbol('messageActions');

const messageSubtreeKey: InjectionKey<MessageSubtreeContext> =
    Symbol('messageSubtree');

/**
 * Publish the viewer-and-channel scope for the whole page. Reactivity is the
 * caller's: pass a `reactive()` bag of computeds and a capability revised
 * mid-session (a channel switch, an archive) reaches every row.
 */
export function provideMessageActions(context: MessageActionsContext): void {
    provide(messageActionsKey, context);
}

/**
 * Read the page's message-action scope.
 *
 * Throws rather than returning `undefined`: a missing provider is a wiring
 * mistake, and raw `inject` would hide it until a click landed on a handler
 * that was never there. Tests provide a stub facade through
 * {@link provideMessageActions} and assert against it, which is what makes a
 * row mountable without its page.
 */
export function useMessageActionsContext(): MessageActionsContext {
    const context = inject(messageActionsKey, null);

    if (context === null) {
        throw new Error(
            'No message-actions context was provided. Call provideMessageActions() on the page rendering this timeline.',
        );
    }

    return context;
}

/** Publish the scope one timeline owns, from the component that owns it. */
export function provideMessageSubtree(subtree: MessageSubtreeContext): void {
    provide(messageSubtreeKey, subtree);
}

/** Read the enclosing timeline's scope; throws for the same reason as above. */
export function useMessageSubtree(): MessageSubtreeContext {
    const subtree = inject(messageSubtreeKey, null);

    if (subtree === null) {
        throw new Error(
            'No message subtree was provided. Call provideMessageSubtree() on the pane or panel rendering this timeline.',
        );
    }

    return subtree;
}

/**
 * The bridge from the provided scopes to the pure guards in
 * `lib/messageActions`: everything but `pending` is already known here, and
 * `pending` is the one fact only the row has. Building
 * {@link MessageActionContext} in this one place is what keeps the hover
 * toolbar, the touch sheet and the row itself from drifting apart.
 */
export function useMessageActionGuards(): {
    contextFor: (pending: boolean) => MessageActionContext;
} {
    const context = useMessageActionsContext();
    const subtree = useMessageSubtree();

    return {
        contextFor: (pending) => ({
            currentUserId: context.currentUserId,
            canReact: context.canReact,
            canPin: context.canPin,
            canModerate: context.canModerate,
            inThread: subtree.inThread,
            pending,
        }),
    };
}
