<?php

namespace App\Data;

use App\Enums\LinkPreviewStatus;
use App\Enums\MessageType;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\MessageLinkPreview;
use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MessageData extends Data
{
    /**
     * @param  array<int, MentionData>  $mentions
     * @param  array<int, LinkPreviewData>  $linkPreviews
     * @param  array<int, ReactionData>  $reactions
     * @param  array<int, AttachmentData>  $attachments
     * @param  array<int, MentionData>  $threadParticipants
     */
    public function __construct(
        public string $id,
        public string $clientUuid,
        public string $body,
        public MessageType $type,
        public UserData $user,
        public string $createdAt,
        public ?string $editedAt,
        public bool $isDeleted,
        public array $mentions,
        public array $linkPreviews,
        public array $reactions,
        public array $attachments,
        public ?PollData $poll,
        public ?PinData $pin,
        public ?MessageReplyData $replyTo,
        public ?MessageForwardData $forwardedFrom,
        public ?string $threadRootId,
        public bool $sentToChannel,
        public int $threadReplyCount,
        public ?string $threadLastReplyAt,
        public array $threadParticipants,
        public bool $threadFollowed = false,
        public bool $threadUnread = false,
    ) {}

    /**
     * Build the DTO from a Message model.
     *
     * The message's `user` and `mentionedUsers` relations should be eager-loaded
     * to avoid N+1 queries. A soft-deleted message renders as a tombstone: its
     * body and mentions are never sent to the client, only the `isDeleted` flag.
     *
     * The `replyTo` relation is expected to be eager-loaded (with its own `user`
     * and `mentionedUsers`) when the message quotes a parent; a deleted parent
     * still resolves so the quote can render a stub. A tombstone carries no
     * quote of its own — a deleted message shows only its own placeholder.
     *
     * The `forwardedFrom` relation follows the same rules (eager-loaded with its
     * `user`, `channel`, and `mentionedUsers`) for a forwarded message.
     *
     * Thread aggregates (`threadReplyCount`, `threadLastReplyAt`,
     * `threadParticipants`) are structural, so they survive a soft delete — a
     * deleted root still shows its "N replies" affordance. `threadParticipants`
     * resolves from the eager-loaded relation when present, empty otherwise.
     *
     * `threadFollowed` and `threadUnread` are per-viewer and only populated when
     * the message was loaded through {@see ChannelController::withThreadReadState()};
     * elsewhere (broadcast payloads carry no viewer) they fall back to false and
     * the client keeps whatever state it already derived. `threadUnread` is the
     * conjunction of following the thread and it holding unread replies.
     *
     * `$viewerId` seeds a poll's per-viewer state (an anonymous poll's own
     * selection, which its hidden roster can't convey). It is passed only on the
     * viewer-scoped timeline load; a broadcast omits it, so the payload stays
     * viewer-free and the client preserves the vote state it already holds.
     */
    public static function fromMessage(Message $message, ?string $viewerId = null): self
    {
        $isDeleted = $message->trashed();

        $threadFollowed = (int) ($message->getAttribute('thread_followed') ?? 0) === 1;
        $threadHasUnread = (int) ($message->getAttribute('thread_has_unread') ?? 0) === 1;

        return new self(
            id: $message->id,
            clientUuid: $message->client_uuid,
            body: $isDeleted ? '' : $message->body,
            type: $message->type,
            user: UserData::fromUser($message->user),
            createdAt: $message->created_at->toIso8601String(),
            editedAt: $message->edited_at?->toIso8601String(),
            isDeleted: $isDeleted,
            mentions: $isDeleted ? [] : $message->mentionedUsers->map(fn (User $user): MentionData => MentionData::fromUser($user))->all(),
            linkPreviews: $isDeleted ? [] : $message->linkPreviews
                ->reject(fn (MessageLinkPreview $preview): bool => $preview->status === LinkPreviewStatus::Failed)
                ->map(fn (MessageLinkPreview $preview): LinkPreviewData => LinkPreviewData::fromModel($preview))
                ->values()
                ->all(),
            // A tombstone carries no reactions; DeleteMessage also hard-deletes
            // the rows, so a deleted message aggregates to an empty set either way.
            reactions: $isDeleted ? [] : ReactionData::forMessage($message),
            // A tombstone hides its files just as it hides its body; the rows
            // survive (removed only on force-delete) but never reach the client.
            attachments: $isDeleted ? [] : $message->attachments->map(fn (Attachment $attachment): AttachmentData => AttachmentData::fromAttachment($attachment))->all(),
            // A tombstone hides its poll just as it hides its body; the rows survive
            // (removed only on force-delete) but never reach the client.
            poll: ! $isDeleted && $message->poll !== null ? PollData::fromPoll($message->poll, $viewerId) : null,
            // A tombstone carries no pin; DeleteMessage also removes the pin row,
            // so a deleted message resolves to null either way.
            pin: ! $isDeleted && $message->pin !== null ? PinData::fromPin($message->pin) : null,
            replyTo: ! $isDeleted && $message->replyTo !== null
                ? MessageReplyData::fromMessage($message->replyTo)
                : null,
            forwardedFrom: ! $isDeleted && $message->forwardedFrom !== null
                ? MessageForwardData::fromMessage($message->forwardedFrom)
                : null,
            threadRootId: $message->thread_root_id,
            sentToChannel: $message->sent_to_channel,
            threadReplyCount: $message->reply_count,
            threadLastReplyAt: $message->last_reply_at?->toIso8601String(),
            threadParticipants: $message->relationLoaded('threadParticipants')
                ? $message->threadParticipants->map(fn (User $user): MentionData => MentionData::fromUser($user))->all()
                : [],
            threadFollowed: $threadFollowed,
            threadUnread: $threadFollowed && $threadHasUnread,
        );
    }
}
