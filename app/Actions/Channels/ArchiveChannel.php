<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Enums\AuditAction;
use App\Events\AuditableActionOccurred;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ArchiveChannel
{
    /**
     * Archive a channel, marking it read-only and hidden from the active sidebar.
     *
     * Archiving is a soft state (never a hard delete): messages are retained and
     * stay searchable. An already-archived channel is left untouched, and
     * records nothing — only a fresh archive is a workspace action.
     *
     * `$actor` is who archived it, nullable for a platform-initiated archive
     * with no human causer.
     */
    public function handle(Channel $channel, ?User $actor): Channel
    {
        return DB::transaction(function () use ($channel, $actor): Channel {
            if (! $channel->isArchived()) {
                $channel->update(['archived_at' => now()]);

                event(new AuditableActionOccurred($channel->team, $actor, AuditAction::ChannelArchived, $channel, [
                    'channel_name' => $channel->name,
                ]));
            }

            return $channel;
        });
    }
}
