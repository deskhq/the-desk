import { describe, expect, it } from 'vitest';
import { effectScope } from 'vue';
import { createOutbox } from '@/lib/outbox';
import type { Outbox, OutboxItem, OutboxStorage } from '@/lib/outbox';

/** A Map-backed stand-in for `localStorage`. */
function fakeStorage(seed: Record<string, string> = {}): OutboxStorage {
    const map = new Map(Object.entries(seed));

    return {
        getItem: (key) => map.get(key) ?? null,
        setItem: (key, value) => {
            map.set(key, value);
        },
    };
}

/** A queued send carrying just the fields the outbox tracks. */
function item(overrides: Partial<OutboxItem> = {}): OutboxItem {
    return {
        clientUuid: 'uuid-1',
        body: 'hello',
        replyToId: null,
        attachmentIds: [],
        ...overrides,
    };
}

/** Run `createOutbox` inside a disposable scope so its refs are owned. */
function withOutbox(run: (outbox: Outbox) => void): void {
    const scope = effectScope();

    scope.run(() => {
        run(createOutbox());
    });

    scope.stop();
}

describe('createOutbox', () => {
    it('starts empty', () => {
        withOutbox((outbox) => {
            expect(outbox.items.value).toEqual([]);
            expect(outbox.count.value).toBe(0);
        });
    });

    it('enqueues items in order and reports the count', () => {
        withOutbox((outbox) => {
            outbox.enqueue(item({ clientUuid: 'a' }));
            outbox.enqueue(item({ clientUuid: 'b' }));

            expect(outbox.items.value.map((i) => i.clientUuid)).toEqual([
                'a',
                'b',
            ]);
            expect(outbox.count.value).toBe(2);
        });
    });

    it('dedupes by client uuid, keeping the first enqueue', () => {
        withOutbox((outbox) => {
            outbox.enqueue(item({ clientUuid: 'a', body: 'first' }));
            outbox.enqueue(item({ clientUuid: 'a', body: 'second' }));

            expect(outbox.count.value).toBe(1);
            expect(outbox.items.value[0].body).toBe('first');
        });
    });

    it('reports membership by client uuid', () => {
        withOutbox((outbox) => {
            outbox.enqueue(item({ clientUuid: 'a' }));

            expect(outbox.has('a')).toBe(true);
            expect(outbox.has('missing')).toBe(false);
        });
    });

    it('removes a single item by client uuid', () => {
        withOutbox((outbox) => {
            outbox.enqueue(item({ clientUuid: 'a' }));
            outbox.enqueue(item({ clientUuid: 'b' }));

            outbox.remove('a');

            expect(outbox.items.value.map((i) => i.clientUuid)).toEqual(['b']);
            expect(outbox.has('a')).toBe(false);
        });
    });

    it('ignores removing an unknown client uuid', () => {
        withOutbox((outbox) => {
            outbox.enqueue(item({ clientUuid: 'a' }));

            outbox.remove('missing');

            expect(outbox.count.value).toBe(1);
        });
    });

    it('clears the whole queue', () => {
        withOutbox((outbox) => {
            outbox.enqueue(item({ clientUuid: 'a' }));
            outbox.enqueue(item({ clientUuid: 'b' }));

            outbox.clear();

            expect(outbox.items.value).toEqual([]);
            expect(outbox.count.value).toBe(0);
        });
    });

    describe('persistence', () => {
        it('mirrors the queue to storage under the given key', () => {
            const storage = fakeStorage();
            const scope = effectScope();

            scope.run(() => {
                const outbox = createOutbox({
                    storageKey: 'outbox:c1',
                    storage,
                });
                outbox.enqueue(item({ clientUuid: 'a' }));
                outbox.enqueue(item({ clientUuid: 'b' }));
                outbox.remove('a');
            });
            scope.stop();

            const stored = JSON.parse(storage.getItem('outbox:c1') ?? '[]');
            expect(stored.map((i: OutboxItem) => i.clientUuid)).toEqual(['b']);
        });

        it('rehydrates a saved queue on construction', () => {
            const storage = fakeStorage({
                'outbox:c1': JSON.stringify([
                    item({ clientUuid: 'a', body: 'saved' }),
                ]),
            });
            const scope = effectScope();
            let outbox!: Outbox;

            scope.run(() => {
                outbox = createOutbox({ storageKey: 'outbox:c1', storage });
            });

            expect(outbox.count.value).toBe(1);
            expect(outbox.items.value[0]).toMatchObject({
                clientUuid: 'a',
                body: 'saved',
            });

            scope.stop();
        });

        it('defaults attachmentIds for rows persisted before the field existed', () => {
            const storage = fakeStorage({
                // A legacy row (no attachmentIds) and one with a stray non-string id.
                'outbox:c1': JSON.stringify([
                    { clientUuid: 'a', body: 'legacy', replyToId: null },
                    {
                        clientUuid: 'b',
                        body: 'mixed',
                        replyToId: null,
                        attachmentIds: ['att-1', 7],
                    },
                ]),
            });
            const scope = effectScope();
            let outbox!: Outbox;

            scope.run(() => {
                outbox = createOutbox({ storageKey: 'outbox:c1', storage });
            });

            expect(outbox.items.value[0].attachmentIds).toEqual([]);
            expect(outbox.items.value[1].attachmentIds).toEqual(['att-1']);

            scope.stop();
        });

        it('ignores corrupt or foreign stored data', () => {
            const storage = fakeStorage({
                'outbox:bad': '{not json',
                'outbox:shape': JSON.stringify([{ nope: true }, 42]),
            });
            const scope = effectScope();

            scope.run(() => {
                expect(
                    createOutbox({ storageKey: 'outbox:bad', storage }).count
                        .value,
                ).toBe(0);
                expect(
                    createOutbox({ storageKey: 'outbox:shape', storage }).items
                        .value,
                ).toEqual([]);
            });
            scope.stop();
        });

        it('stays in-memory only when no storage key is given', () => {
            const storage = fakeStorage();
            const scope = effectScope();

            scope.run(() => {
                createOutbox({ storage }).enqueue(item({ clientUuid: 'a' }));
            });
            scope.stop();

            expect(storage.getItem('outbox:c1')).toBeNull();
        });
    });
});
