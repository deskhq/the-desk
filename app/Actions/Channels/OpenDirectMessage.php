<?php

namespace App\Actions\Channels;

use App\Enums\ChannelType;
use App\Enums\ChannelVisibility;
use App\Enums\NotificationLevel;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OpenDirectMessage
{
    /**
     * Find or create the 1:1 direct message between two team members.
     *
     * The participants' UUIDs are sorted and colon-joined into a canonical
     * `dm_key` (a single UUID for a self-DM), so the same pair always maps to the
     * same key regardless of who initiates. The find-or-create runs in a
     * transaction and the `unique(team_id, dm_key)` index guarantees exactly one
     * DM per pair — opening from either direction resolves the same channel.
     */
    public function handle(Team $team, User $initiator, User $target): Channel
    {
        $participantIds = $this->participantIds($initiator, $target);
        $dmKey = $participantIds->implode(':');

        return DB::transaction(function () use ($team, $initiator, $dmKey, $participantIds) {
            $existing = $team->channels()->where('dm_key', $dmKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            $channel = $team->channels()->create([
                'name' => null,
                'slug' => 'dm-'.Str::lower(Str::random(12)),
                'visibility' => ChannelVisibility::Private,
                'type' => ChannelType::Direct,
                'dm_key' => $dmKey,
                'created_by' => $initiator->id,
            ]);

            foreach ($participantIds as $userId) {
                $channel->channelMembers()->create([
                    'user_id' => $userId,
                    'notification_level' => NotificationLevel::All,
                ]);
            }

            return $channel;
        });
    }

    /**
     * The DM's participant UUIDs, de-duplicated and sorted for a canonical key.
     *
     * A self-DM (initiator === target) collapses to a single UUID.
     *
     * @return Collection<int, string>
     */
    private function participantIds(User $initiator, User $target): Collection
    {
        return collect([$initiator->id, $target->id])
            ->unique()
            ->sort()
            ->values();
    }
}
