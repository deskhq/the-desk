import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';

/**
 * A single outgoing channel message held locally while the realtime connection
 * is down, carrying exactly what {@see useMessageActions} needs to re-post it
 * verbatim once the socket returns (keyed on the same client uuid the optimistic
 * row already renders under). The optimistic row itself is added at send time,
 * so the queue only needs the wire payload — not the mention metadata.
 */
export interface OutboxItem {
    clientUuid: string;
    body: string;
    replyToId: string | null;
    /**
     * The ids of the pre-uploaded attachments this send claims, in tray order.
     * Absent on rows persisted before attachments existed, so it is normalised
     * to an empty array on rehydration.
     */
    attachmentIds: string[];
}

/** The slice of the Web Storage API the outbox needs; injectable for tests. */
export interface OutboxStorage {
    getItem: (key: string) => string | null;
    setItem: (key: string, value: string) => void;
}

export interface OutboxOptions {
    /**
     * A per-channel key under which the queue is mirrored to storage so it
     * survives a refresh; omit for an ephemeral, in-memory-only queue.
     */
    storageKey?: string;
    /** The storage backend, defaulting to `localStorage` when available. */
    storage?: OutboxStorage;
}

export interface Outbox {
    /** The queued sends, oldest first — the order they will flush in. */
    items: Ref<OutboxItem[]>;
    /** How many sends are currently queued. */
    count: ComputedRef<number>;
    /** Queue a send, ignoring a client uuid already present (idempotent enqueue). */
    enqueue: (item: OutboxItem) => void;
    /** Whether a client uuid is currently queued. */
    has: (clientUuid: string) => boolean;
    /** Drop a single queued send (e.g. once it has flushed). */
    remove: (clientUuid: string) => void;
    /** Discard the entire queue (the "Discard queue" affordance). */
    clear: () => void;
}

/** `localStorage`, or `undefined` where it is missing or access throws (SSR, privacy mode). */
function defaultStorage(): OutboxStorage | undefined {
    try {
        return typeof localStorage === 'undefined' ? undefined : localStorage;
    } catch {
        return undefined;
    }
}

/** Whether an unknown parsed value has the shape of an {@see OutboxItem}. */
function isOutboxItem(value: unknown): value is OutboxItem {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const candidate = value as Record<string, unknown>;

    return (
        typeof candidate.clientUuid === 'string' &&
        typeof candidate.body === 'string' &&
        (candidate.replyToId === null ||
            typeof candidate.replyToId === 'string')
    );
}

/**
 * Coerce a rehydrated item to the current shape, defaulting `attachmentIds` for
 * rows persisted before the field existed and dropping any non-string ids.
 */
function normalizeOutboxItem(item: OutboxItem): OutboxItem {
    const ids = (item as { attachmentIds?: unknown }).attachmentIds;

    return {
        ...item,
        attachmentIds: Array.isArray(ids)
            ? ids.filter((id): id is string => typeof id === 'string')
            : [],
    };
}

/**
 * An in-memory, per-channel queue of outgoing messages that could not be sent
 * because the realtime connection dropped. Deduped by the client-generated uuid
 * so a double-enqueue (e.g. a resend attempt) never queues the same message
 * twice. Pure and Vue-reactive — no component coupling — so it unit-tests as a
 * plain module and the offline banner + queued row markers derive from it.
 *
 * When a {@link OutboxOptions.storageKey} is given, the queue is mirrored to
 * `localStorage` and rehydrated on construction, so a refresh while offline keeps
 * the queued sends (they re-render and flush on reconnect). Corrupt or foreign
 * stored data is ignored rather than throwing.
 */
export function createOutbox(options: OutboxOptions = {}): Outbox {
    const storageKey = options.storageKey;
    const storage = options.storage ?? defaultStorage();
    const canPersist = storageKey !== undefined && storage !== undefined;

    function hydrate(): OutboxItem[] {
        if (!canPersist) {
            return [];
        }

        try {
            const raw = storage!.getItem(storageKey!);

            if (raw === null) {
                return [];
            }

            const parsed: unknown = JSON.parse(raw);

            return Array.isArray(parsed)
                ? parsed.filter(isOutboxItem).map(normalizeOutboxItem)
                : [];
        } catch {
            return [];
        }
    }

    const items = ref<OutboxItem[]>(hydrate());

    /** Mirror the current queue to storage; a full/unavailable quota is ignored. */
    function persist(): void {
        if (!canPersist) {
            return;
        }

        try {
            storage!.setItem(storageKey!, JSON.stringify(items.value));
        } catch {
            // Storage being full or blocked must never break sending.
        }
    }

    const count = computed(() => items.value.length);

    function has(clientUuid: string): boolean {
        return items.value.some((item) => item.clientUuid === clientUuid);
    }

    function enqueue(item: OutboxItem): void {
        if (has(item.clientUuid)) {
            return;
        }

        items.value.push(item);
        persist();
    }

    function remove(clientUuid: string): void {
        items.value = items.value.filter(
            (item) => item.clientUuid !== clientUuid,
        );
        persist();
    }

    function clear(): void {
        items.value = [];
        persist();
    }

    return { items, count, enqueue, has, remove, clear };
}
