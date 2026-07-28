import { router } from '@inertiajs/vue3';
import {
    destroy as destroyMessage,
    update as updateMessage,
} from '@/actions/App/Http/Controllers/Channels/MessageController';
import { store as toggleReactionAction } from '@/actions/App/Http/Controllers/Channels/ReactionController';
import type { MessageActionsOptions } from '@/composables/useMessageActions';
import { useToast } from '@/composables/useToast';
import { useTranslations } from '@/composables/useTranslations';
import { toggleReaction } from '@/lib/reactions';
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
    const toast = useToast();

    /** Patch a message into both timelines at once; ignored where it isn't shown. */
    function applyPatch(message: Message): void {
        options.mainStream.applyPatch(message);
        options.threadStream.applyPatch(message);
    }

    function editMessage(message: Message, body: string): void {
        const channel = options.channel();
        const previousMain = options.mainStream.getPatch(message.clientUuid);
        const previousThread = options.threadStream.getPatch(
            message.clientUuid,
        );

        // Optimistically show the edit; the broadcast echo later confirms it.
        applyPatch({ ...message, body, editedAt: new Date().toISOString() });

        router.patch(
            updateMessage({
                team: options.teamSlug(),
                channel: channel.slug,
                message: message.id,
            }).url,
            { body },
            {
                preserveScroll: true,
                onError: () => {
                    options.mainStream.restorePatch(
                        message.clientUuid,
                        previousMain,
                    );
                    options.threadStream.restorePatch(
                        message.clientUuid,
                        previousThread,
                    );
                    toast.error(t('Your edit failed to save'));
                },
            },
        );
    }

    function deleteMessage(message: Message): void {
        const channel = options.channel();
        const previousMain = options.mainStream.getPatch(message.clientUuid);
        const previousThread = options.threadStream.getPatch(
            message.clientUuid,
        );

        // Optimistically show the tombstone; the broadcast echo later confirms it.
        applyPatch({ ...message, body: '', isDeleted: true });

        router.delete(
            destroyMessage({
                team: options.teamSlug(),
                channel: channel.slug,
                message: message.id,
            }).url,
            {
                preserveScroll: true,
                onError: () => {
                    options.mainStream.restorePatch(
                        message.clientUuid,
                        previousMain,
                    );
                    options.threadStream.restorePatch(
                        message.clientUuid,
                        previousThread,
                    );
                    toast.error(t('Failed to delete the message'));
                },
            },
        );
    }

    function reactToMessage(message: Message, emoji: string): void {
        const channel = options.channel();
        const previousMain = options.mainStream.getPatch(message.clientUuid);
        const previousThread = options.threadStream.getPatch(
            message.clientUuid,
        );

        const next = toggleReaction(
            message.reactions,
            emoji,
            options.currentUser(),
        );
        options.mainStream.patchReactions(message.id, next);
        options.threadStream.patchReactions(message.id, next);

        router.post(
            toggleReactionAction({
                team: options.teamSlug(),
                channel: channel.slug,
                message: message.id,
            }).url,
            { emoji },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['channels'],
                onError: () => {
                    options.mainStream.restorePatch(
                        message.clientUuid,
                        previousMain,
                    );
                    options.threadStream.restorePatch(
                        message.clientUuid,
                        previousThread,
                    );
                    toast.error(t('Failed to update the reaction'));
                },
            },
        );
    }

    return { editMessage, deleteMessage, reactToMessage };
}
