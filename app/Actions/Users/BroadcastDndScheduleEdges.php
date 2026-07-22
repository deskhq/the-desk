<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Events\UserProfileUpdated;
use App\Models\User;

class BroadcastDndScheduleEdges
{
    /**
     * Broadcast a profile update for every user whose quiet-hours window opens
     * or closes this very minute, so teammates' open clients flip the DND badge
     * without a reload.
     *
     * A recurring window changes nothing on the row when it starts or ends —
     * unlike a lapsing manual pause there is no column to null — so nothing
     * else would ever announce the flip. The match is the user's own wall
     * clock against their stored `HH:MM` bounds: stateless on purpose, at the
     * price that a minute the scheduler skips loses that minute's announcements
     * (the next props reload still corrects every client).
     *
     * @return int the number of users whose edge was broadcast
     */
    public function handle(): int
    {
        $broadcast = 0;

        User::query()
            ->where('dnd_schedule_enabled', true)
            ->whereNotNull('dnd_starts_at')
            ->whereNotNull('dnd_ends_at')
            ->cursor()
            ->each(function (User $user) use (&$broadcast): void {
                $wallClock = now($user->timezone ?? config('app.timezone'))->format('H:i');

                if ($wallClock !== $user->dnd_starts_at && $wallClock !== $user->dnd_ends_at) {
                    return;
                }

                event(new UserProfileUpdated($user));

                $broadcast++;
            });

        return $broadcast;
    }
}
