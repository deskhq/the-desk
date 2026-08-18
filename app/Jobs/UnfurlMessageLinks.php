<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\MessageData;
use App\Enums\LinkPreviewStatus;
use App\Events\MessageUpdated;
use App\Models\Message;
use App\Support\Unfurl\Unfurler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class UnfurlMessageLinks implements ShouldQueue
{
    use Queueable;

    public function __construct(private string $messageId) {}

    /**
     * Unfurl the message's pending link previews and broadcast the enriched
     * message so open timelines swap each skeleton for its card in place.
     *
     * Re-fetches by id (the message may have been edited or deleted since the job
     * was queued) and bails quietly when it's gone or trashed. Each pending row is
     * resolved to Ready with its Open Graph metadata, or Failed when it can't be
     * fetched. Failed rows are dropped from the DTO so no broken card renders.
     *
     * The whole batch goes in one call, because the unfurl service fetches them
     * concurrently: a message linking three slow hosts costs the longest of them
     * rather than the sum. This used to be a loop, which is why the production
     * stack still runs a second queue worker to keep it off the broadcast path.
     */
    public function handle(Unfurler $unfurler): void
    {
        $message = Message::withTrashed()->find($this->messageId);

        if ($message === null || $message->trashed()) {
            return;
        }

        $pending = $message->linkPreviews()->where('status', LinkPreviewStatus::Pending)->get();

        if ($pending->isEmpty()) {
            return;
        }

        /** @var list<string> $urls */
        $urls = $pending->pluck('url')->unique()->values()->all();

        $previews = $unfurler->unfurl($urls);

        foreach ($pending as $preview) {
            $metadata = $previews[$preview->url] ?? null;

            $preview->update($metadata === null
                ? ['status' => LinkPreviewStatus::Failed]
                : [
                    'status' => LinkPreviewStatus::Ready,
                    'title' => $metadata->title,
                    'description' => $metadata->description,
                    'image_url' => $metadata->image,
                    'site_name' => $metadata->siteName,
                ]);
        }

        $message->loadMessageDataRelations();
        event(new MessageUpdated($message->channel, MessageData::fromMessage($message)));
    }
}
