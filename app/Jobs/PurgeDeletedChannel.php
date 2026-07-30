<?php

namespace App\Jobs;

use App\Models\Attachment;
use App\Models\Channel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PurgeDeletedChannel implements ShouldQueue
{
    use Queueable;

    /**
     * How long a deleted channel stays restorable before it is purged.
     *
     * A fixed window for now. Once workspace-level retention lands this becomes
     * the default rather than the only answer (issue #401).
     */
    public const int GRACE_WINDOW_DAYS = 30;

    public function __construct(public readonly string $channelId) {}

    /**
     * Permanently destroy a deleted channel once its grace window has closed.
     *
     * Everything hanging off the channel — messages (and through them mentions,
     * reactions, polls, link previews, reminders), memberships, pins, scheduled
     * messages, and incoming webhooks — is removed by the database's own
     * `channel_id` cascade when the row goes, so this only has to force-delete
     * the row itself. Attachments are the exception: their blobs are reclaimed by
     * the model's `forceDeleted` hook, which a database-level cascade would never
     * fire, so they are force-deleted through Eloquent first — soft-deleted ones
     * included, since their files are still on disk.
     *
     * Safe to run twice, and safe to run against a channel that has since been
     * restored: the row is re-read here and anything but a still-deleted channel
     * past its window is left alone. That also makes the window authoritative at
     * purge time rather than at dispatch time, so a delete → restore → delete
     * cycle gets a fresh window rather than the original one.
     */
    public function handle(): void
    {
        $channel = Channel::withTrashed()->find($this->channelId);

        if ($channel === null || ! $channel->trashed()) {
            return;
        }

        if ($channel->deleted_at->isAfter(now()->subDays(self::GRACE_WINDOW_DAYS))) {
            return;
        }

        Attachment::withTrashed()
            ->where('channel_id', $channel->id)
            ->cursor()
            ->each(fn (Attachment $attachment): ?bool => $attachment->forceDelete());

        $channel->forceDelete();
    }
}
