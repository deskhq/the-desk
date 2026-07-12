import { ref, watch } from 'vue';
import type { Ref } from 'vue';
import { translate } from '@/lib/i18n';
import type { Message } from '@/types';

/**
 * Drives a visually-hidden `aria-live="polite"` region for the message timeline.
 *
 * Screen readers get no signal when a broadcast message is appended, so this
 * exposes an `announcement` string that a live region renders. It announces only
 * *genuine inbound arrivals* — messages authored by someone other than the
 * viewer that land at the tail of the timeline — so it never narrates the
 * viewer's own optimistic sends, nor the block of older history that
 * `loadOlder` prepends above the current view (which would flood the reader).
 *
 * @param options.messages       Getter for the rendered, chronologically sorted timeline.
 * @param options.currentUserId  Getter for the viewing user's id.
 */
export function useMessageAnnouncer(options: {
    messages: () => Message[];
    currentUserId: () => string;
}): { announcement: Ref<string> } {
    const announcement = ref('');

    const known = new Set<string>();
    let latestSeen = '';
    let seeded = false;

    const rememberAll = (messages: Message[]): void => {
        for (const message of messages) {
            known.add(message.clientUuid);

            if (message.createdAt > latestSeen) {
                latestSeen = message.createdAt;
            }
        }
    };

    watch(
        () => options.messages(),
        (next) => {
            // The timeline the viewer opens with is history, never announced.
            if (!seeded) {
                seeded = true;
                rememberAll(next);

                return;
            }

            // Genuine tail arrivals from other members, newest last. Older
            // history merged in above the pointer (createdAt <= what we've
            // already seen) and the viewer's own sends are never announced.
            const inbound = next.filter(
                (message) =>
                    !known.has(message.clientUuid) &&
                    message.user.id !== options.currentUserId() &&
                    message.createdAt >= latestSeen,
            );

            const newest = inbound.at(-1);

            rememberAll(next);

            if (newest) {
                announcement.value = translate(
                    'New message from :author: :message',
                    { author: newest.user.name, message: newest.body },
                );
            }
        },
        { immediate: true },
    );

    return { announcement };
}
