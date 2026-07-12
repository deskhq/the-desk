import { describe, expect, it } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import { useMessageAnnouncer } from '@/composables/useMessageAnnouncer';
import type { Message } from '@/types';

/** A message with just the fields the announcer reads. */
function message(
    clientUuid: string,
    userId: string,
    name: string,
    body: string,
    createdAt: string,
): Message {
    return {
        clientUuid,
        user: { id: userId, name },
        body,
        createdAt,
    } as unknown as Message;
}

function withScope(messages: () => Message[], currentUserId = 'me') {
    const scope = effectScope();
    let announcer!: ReturnType<typeof useMessageAnnouncer>;

    scope.run(() => {
        announcer = useMessageAnnouncer({
            messages,
            currentUserId: () => currentUserId,
        });
    });

    return { announcer, unmount: () => scope.stop() };
}

describe('useMessageAnnouncer', () => {
    it('does not announce the history already present on first load', () => {
        const { announcer } = withScope(() => [
            message('a', 'peer', 'Bob', 'earlier', '2026-07-12T10:00:00Z'),
            message('b', 'me', 'Me', 'mine', '2026-07-12T10:01:00Z'),
        ]);

        expect(announcer.announcement.value).toBe('');
    });

    it('announces an inbound message from another member', async () => {
        const list = ref<Message[]>([
            message('a', 'peer', 'Bob', 'earlier', '2026-07-12T10:00:00Z'),
        ]);
        const { announcer } = withScope(() => list.value);

        list.value = [
            ...list.value,
            message('b', 'peer', 'Bob', 'hello there', '2026-07-12T10:05:00Z'),
        ];
        await nextTick();

        expect(announcer.announcement.value).toBe(
            'New message from Bob: hello there',
        );
    });

    it('does not announce the viewer’s own new message', async () => {
        const list = ref<Message[]>([
            message('a', 'peer', 'Bob', 'earlier', '2026-07-12T10:00:00Z'),
        ]);
        const { announcer } = withScope(() => list.value);

        list.value = [
            ...list.value,
            message('b', 'me', 'Me', 'my own send', '2026-07-12T10:05:00Z'),
        ];
        await nextTick();

        expect(announcer.announcement.value).toBe('');
    });

    it('does not announce older history merged in above the pointer', async () => {
        const list = ref<Message[]>([
            message('c', 'peer', 'Bob', 'latest', '2026-07-12T10:05:00Z'),
        ]);
        const { announcer } = withScope(() => list.value);

        // loadOlder prepends messages with earlier timestamps.
        list.value = [
            message('a', 'peer', 'Bob', 'old one', '2026-07-12T09:00:00Z'),
            message('b', 'peer', 'Bob', 'old two', '2026-07-12T09:30:00Z'),
            ...list.value,
        ];
        await nextTick();

        expect(announcer.announcement.value).toBe('');
    });
});
