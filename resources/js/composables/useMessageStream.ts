import { computed, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { Mention, Message, MessageForward } from '@/types';

/**
 * The reactive merge engine behind a message list.
 *
 * A rendered list is the union of three client-side sources layered over the
 * server page, all deduped by the client-generated uuid the server persists:
 *
 *  - `pending` — optimistic local sends awaiting confirmation,
 *  - `live` — messages arriving over the realtime broadcast channel,
 *  - `patches` — edits/deletions overlaid in place (own mutations + echoes).
 *
 * Server copy wins over live wins over the optimistic copy; patches overlay
 * whichever copy is rendered, keeping its slot. The same engine drives both the
 * main channel timeline and a thread panel, so optimistic + realtime behaviour
 * is identical in both places.
 */
export function useMessageStream(
    serverMessages: Ref<Message[]> | ComputedRef<Message[]>,
) {
    const pending = ref<Message[]>([]);
    const live = ref<Message[]>([]);
    const patches = ref<Map<string, Message>>(new Map());

    const displayMessages = computed<Message[]>(() => {
        const byUuid = new Map<string, Message>();

        for (const message of serverMessages.value) {
            byUuid.set(message.clientUuid, message);
        }

        for (const message of live.value) {
            if (!byUuid.has(message.clientUuid)) {
                byUuid.set(message.clientUuid, message);
            }
        }

        for (const message of pending.value) {
            if (!byUuid.has(message.clientUuid)) {
                byUuid.set(message.clientUuid, message);
            }
        }

        // Overlay edits/deletions on the rendered copy, keeping its original slot.
        for (const [uuid, patch] of patches.value) {
            if (byUuid.has(uuid)) {
                byUuid.set(uuid, patch);
            }
        }

        return [...byUuid.values()].sort((a, b) =>
            a.createdAt < b.createdAt ? -1 : a.createdAt > b.createdAt ? 1 : 0,
        );
    });

    const pendingUuids = computed(() =>
        pending.value.map((message) => message.clientUuid),
    );

    // Drop optimistic messages once the server page or a live echo confirms them.
    const confirmedUuids = computed(
        () =>
            new Set([
                ...serverMessages.value.map((message) => message.clientUuid),
                ...live.value.map((message) => message.clientUuid),
            ]),
    );

    watch(confirmedUuids, (uuids) => {
        pending.value = pending.value.filter(
            (message) => !uuids.has(message.clientUuid),
        );
    });

    /**
     * Record a message received over the broadcast channel. Returns whether it
     * was newly added, so the caller can decide to auto-scroll.
     */
    function appendLive(message: Message): boolean {
        const known =
            live.value.some((m) => m.clientUuid === message.clientUuid) ||
            serverMessages.value.some(
                (m) => m.clientUuid === message.clientUuid,
            );

        if (known) {
            return false;
        }

        live.value.push(message);

        return true;
    }

    /**
     * Locate the currently-rendered copy of a message by client uuid, preferring
     * an existing patch, then a live echo, then the server page.
     */
    function currentCopy(clientUuid: string): Message | undefined {
        return (
            patches.value.get(clientUuid) ??
            live.value.find((m) => m.clientUuid === clientUuid) ??
            serverMessages.value.find((m) => m.clientUuid === clientUuid)
        );
    }

    function applyPatch(message: Message): void {
        // A broadcast patch (edit, delete, thread reply-count bump) carries no
        // viewer context, so its `threadFollowed`/`threadUnread` are always the
        // server defaults. Preserve whatever the client has already derived for
        // this message so a root's dot isn't cleared by its own reply-count bump.
        const prior = currentCopy(message.clientUuid);

        patches.value.set(message.clientUuid, {
            ...message,
            threadFollowed: prior?.threadFollowed ?? message.threadFollowed,
            threadUnread: prior?.threadUnread ?? message.threadUnread,
        });
    }

    /**
     * Overlay per-viewer thread read-state on a rendered message, found by its
     * message id (the id a reply carries as `threadRootId`). Used to raise or
     * clear a root's unread dot and mark it followed as the viewer engages,
     * keeping every other field of the rendered copy intact.
     */
    function patchThreadState(id: string, partial: Partial<Message>): void {
        const current = displayMessages.value.find((m) => m.id === id);

        if (!current) {
            return;
        }

        patches.value.set(current.clientUuid, { ...current, ...partial });
    }

    function getPatch(clientUuid: string): Message | undefined {
        return patches.value.get(clientUuid);
    }

    function restorePatch(
        clientUuid: string,
        previous: Message | undefined,
    ): void {
        if (previous) {
            patches.value.set(clientUuid, previous);
        } else {
            patches.value.delete(clientUuid);
        }
    }

    function addPending(message: Message): void {
        pending.value.push(message);
    }

    function removePending(clientUuid: string): void {
        pending.value = pending.value.filter(
            (message) => message.clientUuid !== clientUuid,
        );
    }

    function reset(): void {
        live.value = [];
        pending.value = [];
        patches.value = new Map();
    }

    return {
        displayMessages,
        pendingUuids,
        appendLive,
        applyPatch,
        patchThreadState,
        getPatch,
        restorePatch,
        addPending,
        removePending,
        reset,
    };
}

/**
 * Build the optimistic copy of a just-sent message so it renders immediately,
 * before the server echo replaces it (keyed on the same client uuid).
 */
export function optimisticMessage(params: {
    clientUuid: string;
    body: string;
    author: Mention;
    mentions: Mention[];
    replyTo?: Message | null;
    forwardedFrom?: MessageForward | null;
    threadRootId?: string | null;
    sentToChannel?: boolean;
}): Message {
    const target = params.replyTo ?? null;

    return {
        id: params.clientUuid,
        clientUuid: params.clientUuid,
        body: params.body,
        user: params.author,
        createdAt: new Date().toISOString(),
        editedAt: null,
        isDeleted: false,
        mentions: params.mentions,
        // Previews resolve server-side; the echo replaces this optimistic copy
        // with one carrying any pending skeletons.
        linkPreviews: [],
        replyTo: target
            ? {
                  id: target.id,
                  body: target.body,
                  authorName: target.user.name,
                  isDeleted: target.isDeleted,
                  mentions: target.mentions,
              }
            : null,
        forwardedFrom: params.forwardedFrom ?? null,
        threadRootId: params.threadRootId ?? null,
        sentToChannel: params.sentToChannel ?? false,
        threadReplyCount: 0,
        threadLastReplyAt: null,
        threadParticipants: [],
        threadFollowed: false,
        threadUnread: false,
    };
}
