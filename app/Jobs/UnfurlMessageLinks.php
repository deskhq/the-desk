<?php

namespace App\Jobs;

use App\Data\MessageData;
use App\Enums\LinkPreviewStatus;
use App\Events\MessageUpdated;
use App\Models\Message;
use App\Support\FetchLinkPreview;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UnfurlMessageLinks implements ShouldQueue
{
    use Queueable;

    /**
     * The relations {@see MessageData::fromMessage()} reads to build the broadcast.
     *
     * @var array<int, string>
     */
    private const array MESSAGE_RELATIONS = [
        'user', 'mentionedUsers', 'linkPreviews',
        'replyTo.user', 'replyTo.mentionedUsers',
        'forwardedFrom.user', 'forwardedFrom.channel', 'forwardedFrom.mentionedUsers',
    ];

    public function __construct(private string $messageId) {}

    /**
     * Unfurl the message's pending link previews and broadcast the enriched
     * message so open timelines swap each skeleton for its card in place.
     *
     * Re-fetches by id (the message may have been edited or deleted since the job
     * was queued) and bails quietly when it's gone or trashed. Each pending row is
     * resolved to Ready with its Open Graph metadata, or Failed when it can't be
     * fetched. Failed rows are dropped from the DTO so no broken card renders.
     */
    public function handle(FetchLinkPreview $fetcher): void
    {
        $message = Message::withTrashed()->find($this->messageId);

        if ($message === null || $message->trashed()) {
            return;
        }

        $pending = $message->linkPreviews()->where('status', LinkPreviewStatus::Pending)->get();

        if ($pending->isEmpty()) {
            return;
        }

        foreach ($pending as $preview) {
            $metadata = $fetcher->handle($preview->url);

            $preview->update($metadata === null
                ? ['status' => LinkPreviewStatus::Failed]
                : [
                    'status' => LinkPreviewStatus::Ready,
                    'title' => $metadata['title'],
                    'description' => $metadata['description'],
                    'image_url' => $metadata['image'],
                    'site_name' => $metadata['siteName'],
                ]);
        }

        $message->load(self::MESSAGE_RELATIONS);
        MessageUpdated::dispatch($message->channel, MessageData::fromMessage($message));
    }
}
