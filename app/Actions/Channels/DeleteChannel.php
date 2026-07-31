<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Enums\AuditAction;
use App\Events\AuditableActionOccurred;
use App\Jobs\PurgeDeletedChannel;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteChannel
{
    /**
     * Delete a channel, hiding it everywhere and starting its grace window.
     *
     * The stamp is all that happens here: {@see PurgeDeletedChannel}
     * does the irreversible work once the window has closed, so until then the
     * channel is fully recoverable through {@see RestoreChannel}.
     *
     * The row is re-read under a lock rather than trusted from the instance the
     * caller resolved: two requests racing to delete the same channel would
     * otherwise both see it live, and the second would overwrite `deleted_at`
     * and silently extend the grace window. Under the lock the loser sees the
     * winner's stamp and leaves it alone, so the channel keeps the deletion date
     * it actually got — which is the date the purge is scheduled from, and the
     * date the audit entry names. Only the request that actually deleted the
     * channel records one; the loser of that race records nothing.
     *
     * The audit entry carries the purge date because it is the only lasting
     * record of the channel once the grace window closes.
     */
    public function handle(Channel $channel, ?User $actor): Channel
    {
        return DB::transaction(function () use ($channel, $actor): Channel {
            $locked = Channel::withTrashed()
                ->whereKey($channel->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->trashed()) {
                $locked->delete();

                event(new AuditableActionOccurred($locked->team, $actor, AuditAction::ChannelDeleted, $locked, [
                    'channel_name' => $locked->name,
                    'purge_at' => $locked->deleted_at->addDays(PurgeDeletedChannel::GRACE_WINDOW_DAYS)->toDateString(),
                ]));
            }

            return $locked;
        });
    }
}
