<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Enums\AuditAction;
use App\Events\AuditableActionOccurred;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestoreChannel
{
    /**
     * Bring a deleted channel back, with everything it still holds.
     *
     * Nothing but `deleted_at` was ever touched, so clearing it restores the
     * channel and its messages, files, pins, and memberships in one write — which
     * is the whole reason deletion is staged this way.
     *
     * Deleting a channel releases its slug, so a teammate may have created a new
     * channel of the same name in the meantime. The slug is the route key and is
     * unique per live channel, so that restore cannot proceed: rather than
     * silently re-slugging the channel — moving its URL out from under every link
     * to it — it is refused, leaving the admin to rename the new channel first.
     *
     * @throws ValidationException when a live channel already holds the slug
     */
    public function handle(Channel $channel, ?User $actor): Channel
    {
        return DB::transaction(function () use ($channel, $actor): Channel {
            $taken = Channel::query()
                ->where('team_id', $channel->team_id)
                ->where('slug', $channel->slug)
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'channel' => __('A channel named #:channel already exists. Rename it before restoring this one.', ['channel' => $channel->slug]),
                ]);
            }

            $channel->restore();

            event(new AuditableActionOccurred($channel->team, $actor, AuditAction::ChannelRestored, $channel, [
                'channel_name' => $channel->name,
            ]));

            return $channel;
        });
    }
}
