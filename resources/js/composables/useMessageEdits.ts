import {
    destroy as destroyMessage,
    update as updateMessage,
} from '@/actions/App/Http/Controllers/Channels/MessageController';
import { store as toggleReactionAction } from '@/actions/App/Http/Controllers/Channels/ReactionController';
import type { MessageActionsOptions } from '@/composables/useMessageActions';
import {
    snapshotStreams,
    useOptimisticWrite,
} from '@/composables/useOptimisticWrite';
import type { Rollback } from '@/composables/useOptimisticWrite';
import { useTranslations } from '@/composables/useTranslations';
import { toggleReaction } from '@/lib/reactions';
import { CHANNEL_LIST_PROPS } from '@/lib/reloadProps';
import type { Message } from '@/types';

export interface MessageEdits {
    /** Save an edit, optimistically, rolling the patch back on error. */
    editMessage: (message: Message, body: string) => void;
    /** Delete a message, optimistically, rolling the tombstone back on error. */
    deleteMessage: (message: Message) => void;
    /** Toggle the viewer's reaction, optimistically, rolled back on error. */
    reactToMessage: (message: Message, emoji: string) => void;
}

export type MessageEditsOptions = Pick<
    MessageActionsOptions,
    'teamSlug' | 'channel' | 'currentUser' | 'mainStream' | 'threadStream'
>;

/**
 * The three ways an existing row changes in place: its body, its tombstone, and
 * the viewer's reaction. All follow the same shape — snapshot both streams,
 * patch optimistically, restore and toast if the write is refused.
 */
export function useMessageEdits(options: MessageEditsOptions): MessageEdits {
    const { t } = useTranslations();
    const { write } = useOptimisticWrite();

    /** Patch a message into both timelines at once; ignored where it isn't shown. */
    function applyPatch(message: Message): void {
        options.mainStream.applyPatch(message);
        options.threadStream.applyPatch(message);
    }

    /** Snapshot the row wherever it is showing, so a refusal restores both. */
    function snapshotBothStreams(message: Message): Rollback {
        return snapshotStreams(
            message.clientUuid,
            options.mainStream,
            options.threadStream,
        );
    }

    function editMessage(message: Message, body: string): void {
        const channel = options.channel();

        write({
            capture: () => snapshotBothStreams(message),
            // Optimistically show the edit; the broadcast echo later confirms it.
            apply: () =>
                applyPatch({
                    ...message,
                    body,
                    editedAt: new Date().toISOString(),
                }),
            method: 'patch',
            url: updateMessage({
                team: options.teamSlug(),
                channel: channel.slug,
                message: message.id,
            }).url,
            data: { body },
            // Named no props and kept none: the response replaces the whole page
            // prop set, which is how the edited row reaches every surface reading
            // it rather than just the two streams patched above.
            preserveState: false,
            failure: t('Your edit failed to save'),
        });
    }

    function deleteMessage(message: Message): void {
        const channel = options.channel();

        write({
            capture: () => snapshotBothStreams(message),
            // Optimistically show the tombstone; the broadcast echo later confirms it.
            apply: () => applyPatch({ ...message, body: '', isDeleted: true }),
            method: 'delete',
            url: destroyMessage({
                team: options.teamSlug(),
                channel: channel.slug,
                message: message.id,
            }).url,
            preserveState: false,
            failure: t('Failed to delete the message'),
        });
    }

    function reactToMessage(message: Message, emoji: string): void {
        const channel = options.channel();
        const next = toggleReaction(
            message.reactions,
            emoji,
            options.currentUser(),
        );

        write({
            capture: () => snapshotBothStreams(message),
            apply: () => {
                options.mainStream.patchReactions(message.id, next);
                options.threadStream.patchReactions(message.id, next);
            },
            url: toggleReactionAction({
                team: options.teamSlug(),
                channel: channel.slug,
                message: message.id,
            }).url,
            data: { emoji },
            only: CHANNEL_LIST_PROPS,
            failure: t('Failed to update the reaction'),
        });
    }

    return { editMessage, deleteMessage, reactToMessage };
}
