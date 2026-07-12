<?php

namespace App\Actions\Channels;

use App\Models\Channel;
use Illuminate\Support\Facades\DB;

class ArchiveChannel
{
    /**
     * Archive a channel, marking it read-only and hidden from the active sidebar.
     *
     * Archiving is a soft state (never a hard delete): messages are retained and
     * stay searchable. An already-archived channel is left untouched.
     */
    public function handle(Channel $channel): Channel
    {
        return DB::transaction(function () use ($channel): Channel {
            if (! $channel->isArchived()) {
                $channel->update(['archived_at' => now()]);
            }

            return $channel;
        });
    }
}
