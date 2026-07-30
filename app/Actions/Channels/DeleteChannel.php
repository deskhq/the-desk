<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Jobs\PurgeDeletedChannel;
use App\Models\Channel;
use Illuminate\Support\Facades\DB;

class DeleteChannel
{
    /**
     * Delete a channel, hiding it everywhere and starting its grace window.
     *
     * The stamp is all that happens here: {@see PurgeDeletedChannel}
     * does the irreversible work once the window has closed, so until then the
     * channel is fully recoverable through {@see RestoreChannel}. An already
     * deleted channel keeps its original `deleted_at` rather than having its
     * window extended, which makes a double submit a no-op.
     */
    public function handle(Channel $channel): Channel
    {
        return DB::transaction(function () use ($channel): Channel {
            if (! $channel->trashed()) {
                $channel->delete();
            }

            return $channel;
        });
    }
}
