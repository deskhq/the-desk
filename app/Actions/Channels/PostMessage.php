<?php

namespace App\Actions\Channels;

use App\Data\MessageData;
use App\Enums\AttachmentStatus;
use App\Events\DirectMessageStarted;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostMessage
{
    public function __construct(
        private readonly SyncMentions $syncMentions,
        private readonly SyncLinkPreviews $syncLinkPreviews,
    ) {}

    /**
     * Post a message to a channel on behalf of a user.
     *
     * Keyed on the client-generated uuid so a resent optimistic message resolves
     * to the row that already exists instead of creating a duplicate. Only a
     * genuinely new message parses its mentions and broadcasts, keeping the retry
     * path side-effect free.
     *
     * When `$replyToId` is set the message renders as an inline quote of that
     * parent. When `$forwardedFromId` is set the message forwards that (possibly
     * cross-channel) source, rendered with its attribution and quote; the body is
     * an optional note. When `$threadRootId` is set the message is a thread reply:
     * it stays out of the main timeline unless `$sentToChannel` is true, and it
     * bumps the root's denormalized reply count / last-reply time. The request
     * layer has already checked every reference points at a live, permitted message.
     *
     * A main-composer send clears the author's channel draft, since scheduling or
     * sending consumes its text. A delayed delivery (the scheduler replaying a
     * scheduled message) passes `$clearDraft: false` so it never wipes a draft the
     * author has typed in the meantime.
     *
     * `$attachmentIds` are the pending uploads this send claims: they are linked
     * to the message in the same transaction that creates it, so a partially
     * claimed send can never persist. A `client_uuid` retry re-runs the claim
     * against the existing row and resolves each already-linked id to a no-op,
     * keeping the path idempotent.
     *
     * @param  list<string>  $attachmentIds
     */
    public function handle(Channel $channel, User $author, string $body, string $clientUuid, ?string $replyToId = null, ?string $forwardedFromId = null, ?string $threadRootId = null, bool $sentToChannel = false, bool $clearDraft = true, array $attachmentIds = []): Message
    {
        $message = DB::transaction(function () use ($channel, $author, $body, $clientUuid, $replyToId, $forwardedFromId, $threadRootId, $sentToChannel, $attachmentIds): Message {
            $message = $channel->messages()->firstOrCreate(
                ['client_uuid' => $clientUuid],
                [
                    'user_id' => $author->id,
                    'body' => $body,
                    'reply_to_id' => $replyToId,
                    'forwarded_from_id' => $forwardedFromId,
                    'thread_root_id' => $threadRootId,
                    'sent_to_channel' => $threadRootId !== null && $sentToChannel,
                ],
            );

            // Claim on every call, not just the create branch: a client_uuid
            // retry re-sends the same ids after they are already linked to this
            // message, and claiming resolves each to a no-op so the send stays
            // idempotent (while still rejecting an id claimed by another message).
            $this->claimAttachments($channel, $author, $message, $attachmentIds);

            return $message;
        });

        if ($message->wasRecentlyCreated) {
            $this->syncMentions->handle($channel, $message);
            $this->syncLinkPreviews->handle($message);
            $message->loadMessageDataRelations();
            event(new MessageSent($channel, MessageData::fromMessage($message)));

            $this->announceFirstDirectMessage($channel, $author);

            if ($threadRootId !== null) {
                $this->bumpThreadRoot($channel, $threadRootId);
            } elseif ($clearDraft) {
                // A message sent from the main composer clears its channel draft;
                // a thread reply leaves the channel draft alone (it isn't its text),
                // and a delayed scheduled delivery leaves it alone too.
                $author->channels()->updateExistingPivot($channel->id, ['draft' => null]);
            }
        }

        return $message;
    }

    /**
     * Link the sender's pending uploads to the freshly created message.
     *
     * Runs inside the create transaction, so any rejection rolls the message back
     * with it — the send is all-or-nothing. Only a brand-new message claims: a
     * retry (or a reused `client_uuid`) resolves to an existing message, so a row
     * already attached to *it* is a no-op while any *new* id is rejected — a
     * caller can neither append files to someone else's message via their
     * `client_uuid` nor bypass the per-message cap by retrying with more ids. Each
     * claimed id must be the sender's own pending upload in this channel; a row
     * owned by someone else, from another channel, or already claimed by a
     * different message fails the whole send. The rows are locked so two
     * concurrent sends can't both claim the same pending upload.
     *
     * @param  list<string>  $attachmentIds
     */
    private function claimAttachments(Channel $channel, User $author, Message $message, array $attachmentIds): void
    {
        if ($attachmentIds === []) {
            return;
        }

        $attachments = Attachment::whereIn('id', $attachmentIds)->lockForUpdate()->get();

        // Every requested id must still resolve to a row. The request layer's
        // `exists` rule checks this at validation time, but a row can vanish in
        // the window before the claim (a GC sweep, a force-delete) — and a direct
        // caller skips that layer entirely — so a missing id fails the whole send
        // rather than silently attaching fewer files than asked for.
        if ($attachments->count() !== count(array_unique($attachmentIds))) {
            throw ValidationException::withMessages([
                'attachment_ids' => __('One or more attachments are unavailable.'),
            ]);
        }

        foreach ($attachments as $attachment) {
            if ($attachment->message_id === $message->id) {
                continue;
            }

            // Reached an id not already linked to this message. If the message
            // isn't brand-new, this is a retry (or a reused client_uuid) trying to
            // claim a fresh file — reject it, so the retry stays idempotent for the
            // ids it originally sent and nothing new can be grafted on.
            if (! $message->wasRecentlyCreated) {
                throw ValidationException::withMessages([
                    'attachment_ids' => __('One or more attachments are unavailable.'),
                ]);
            }

            $claimable = $attachment->user_id === $author->id
                && $attachment->channel_id === $channel->id
                && $attachment->status === AttachmentStatus::Pending;

            if (! $claimable) {
                throw ValidationException::withMessages([
                    'attachment_ids' => __('One or more attachments are unavailable.'),
                ]);
            }
        }

        Attachment::whereIn('id', $attachmentIds)->update([
            'message_id' => $message->id,
            'status' => AttachmentStatus::Attached->value,
        ]);
    }

    /**
     * When a direct message receives its very first message, notify the other
     * participant(s) on their private `user.{id}` channel so the DM appears in
     * their sidebar live. Only the first message announces — thereafter the DM is
     * already listed and the sidebar fleet keeps its badge current. A self-DM has
     * no other participant, so nothing is announced.
     */
    private function announceFirstDirectMessage(Channel $channel, User $author): void
    {
        if (! $channel->isDirect() || $channel->messages()->count() !== 1) {
            return;
        }

        $channel->members()
            ->where('users.id', '!=', $author->id)
            ->pluck('users.id')
            ->each(fn (string $recipientId) => event(new DirectMessageStarted($recipientId, $channel->id)));
    }

    /**
     * Advance the root's reply aggregates and broadcast the fresh count so open
     * timelines patch the root's "N replies" affordance live.
     */
    private function bumpThreadRoot(Channel $channel, string $threadRootId): void
    {
        $root = $channel->messages()->withTrashed()->findOrFail($threadRootId);
        $root->forceFill(['reply_count' => $root->reply_count + 1, 'last_reply_at' => now()])->save();

        $root->loadMessageDataRelations();
        event(new MessageUpdated($channel, MessageData::fromMessage($root)));
    }
}
