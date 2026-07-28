import { router } from '@inertiajs/vue3';
import { vi } from 'vitest';
import type { Mock } from 'vitest';
import { effectScope, ref } from 'vue';
import type { Ref } from 'vue';
import { useMessageActions } from '@/composables/useMessageActions';
import type { MessageActions } from '@/composables/useMessageActions';
import { useMessageStream } from '@/composables/useMessageStream';
import { useToast } from '@/composables/useToast';
import { createOutbox } from '@/lib/outbox';
import type { Outbox } from '@/lib/outbox';
import type { Mention, Message } from '@/types';

/**
 * Test-only: the fixtures and mock accessors every `useMessageActions.*.test.ts`
 * file shares. The suite is split by the actions it groups, but they all drive
 * the same composable through the same stubbed router and toast, so the harness
 * lives here rather than being copied into each file and drifting. Nothing the
 * app ships imports it, so it never reaches a bundle.
 *
 * The `vi.mock` calls themselves stay in the test files — vitest hoists them
 * per module graph, so they cannot be shared. This module reads the resulting
 * doubles back off the mocked modules, which is why importing it is only ever
 * correct from a file that installed them.
 */

type Stream = ReturnType<typeof useMessageStream>;

const post = router.post as unknown as Mock;
const patch = router.patch as unknown as Mock;
const destroy = router.delete as unknown as Mock;
const toast = useToast();
const toastError = toast.error as unknown as Mock;
const toastSuccess = toast.success as unknown as Mock;

/** Wipe every recorded call, so each test reads only its own. */
export function clearMessageActionMocks(): void {
    post.mockClear();
    patch.mockClear();
    destroy.mockClear();
    toastError.mockClear();
    toastSuccess.mockClear();
}

/** A message carrying just the fields the actions read. */
export function message(overrides: Partial<Message> = {}): Message {
    return {
        id: 'm1',
        clientUuid: 'uuid-1',
        body: 'hello',
        type: 'standard',
        user: { id: 'peer', name: 'Peer' },
        createdAt: '2024-01-01T00:00:00.000Z',
        editedAt: null,
        isDeleted: false,
        mentions: [],
        linkPreviews: [],
        attachments: [],
        reactions: [],
        pin: null,
        poll: null,
        replyTo: null,
        forwardedFrom: null,
        threadRootId: null,
        sentToChannel: false,
        threadReplyCount: 0,
        threadLastReplyAt: null,
        threadParticipants: [],
        threadFollowed: false,
        threadUnread: false,
        threadUnreadReplyCount: 0,
        ...overrides,
    };
}

export const me: Mention = { id: 'me', name: 'Me' };
export const channel = {
    id: 'chan-1',
    slug: 'general',
    name: 'general',
    isDirect: false,
};

export function harness(
    setup: {
        serverMain?: Message[];
        serverThread?: Message[];
        activeThreadRootId?: string | null;
        replyTarget?: Message | null;
        isNearBottom?: boolean;
        isOnline?: boolean;
    } = {},
) {
    const scope = effectScope();
    const cancelDraft = vi.fn();
    const clearDraft = vi.fn();
    const cancelReply = vi.fn();
    const scrollToBottom = vi.fn();
    const onSendFailure = vi.fn();
    const activeThreadRootId: Ref<string | null> = ref(
        setup.activeThreadRootId ?? null,
    );
    const replyTarget: Ref<Message | null> = ref(setup.replyTarget ?? null);
    /** Flippable so a test can queue offline and then reconnect, as the app does. */
    const isOnline = ref(setup.isOnline ?? true);

    let actions!: MessageActions;
    let mainStream!: Stream;
    let threadStream!: Stream;
    let outbox!: Outbox;

    scope.run(() => {
        mainStream = useMessageStream(ref(setup.serverMain ?? []));
        threadStream = useMessageStream(ref(setup.serverThread ?? []));
        outbox = createOutbox();
        actions = useMessageActions({
            teamSlug: () => 'acme',
            channel: () => channel,
            currentUser: () => me,
            isOnline: () => isOnline.value,
            outbox,
            mainStream,
            threadStream,
            activeThreadRootId,
            replyTarget,
            isNearBottom: () => setup.isNearBottom ?? true,
            scrollToBottom,
            cancelDraft,
            clearDraft,
            cancelReply,
            onSendFailure,
        });
    });

    return {
        actions,
        mainStream,
        threadStream,
        outbox,
        isOnline,
        activeThreadRootId,
        cancelDraft,
        clearDraft,
        cancelReply,
        scrollToBottom,
        onSendFailure,
        unmount: () => scope.stop(),
    };
}

/** The options (third argument) of the nth recorded call on a router mock. */
export function optionsOf(
    mock: Mock,
    call = 0,
): {
    only?: string[];
    onError?: () => void;
    onSuccess?: (page?: unknown) => void;
    onFinish?: () => void;
    onCancel?: () => void;
} {
    return mock.mock.calls[call][2];
}

/**
 * A response page carrying the given flash data. Only `flash` is read by the
 * paths under test, so the rest of the page object is deliberately absent.
 */
export function pageFlashing(flash: Record<string, unknown>): unknown {
    return { flash };
}

/** The payload (second argument) of the nth recorded call on a router mock. */
export function payloadOf(mock: Mock, call = 0): Record<string, unknown> {
    return mock.mock.calls[call][1];
}

/**
 * Drain the microtask queue. A flush resolves through several awaits before it
 * reports its outcome, so a single `nextTick` is not far enough down the chain.
 */
export function settle(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/** `router.delete` takes its options as the second argument (no request body). */
export function deleteOptionsOf(call = 0): {
    only?: string[];
    preserveUrl?: boolean;
    onError?: () => void;
    onSuccess?: () => void;
} {
    return destroy.mock.calls[call][1];
}
