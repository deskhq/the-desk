<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Jobs\PurgeDeletedChannel;
use App\Models\Channel;

final class PurgeExpiredChannels
{
    /**
     * Queue the permanent purge of every deleted channel whose grace window has
     * closed.
     *
     * A sweep rather than a 30-day delayed job dispatched at deletion time: a
     * queue that is drained, migrated, or simply lost between the two dates would
     * strand the channel forever, whereas a daily sweep picks up whatever is due
     * however long the instance was down. The real work happens off the scheduler
     * in {@see PurgeDeletedChannel}, one job per channel, so a large channel's
     * purge cannot hold the sweep open — and each job re-checks the window
     * itself, so a channel restored between sweep and run is left alone.
     *
     * @return int the number of purges queued
     */
    public function handle(): int
    {
        $queued = 0;

        Channel::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays(Channel::RESTORE_WINDOW_DAYS))
            ->cursor()
            ->each(function (Channel $channel) use (&$queued): void {
                dispatch(new PurgeDeletedChannel($channel->id));

                $queued++;
            });

        return $queued;
    }
}
