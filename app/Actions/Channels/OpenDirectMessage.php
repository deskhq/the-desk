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
        return $this->openForUsers($team, $initiator, collect([$target]));
    }

    /**
     * Find or create the direct message spanning the initiator and the given
     * participants — a 1:1 when the set is one or two people, a group DM (3+).
     *
     * The full participant set (initiator included) is sorted and, for a group,
     * hashed into a canonical `dm_key` — a `g:`-prefixed SHA-256, distinct from
     * the raw colon-joined key a 1:1 uses so the two formats never collide and
     * existing 1:1 keys keep resolving unchanged. The same member set therefore
     * always maps to the same key regardless of who opens it or in what order, so
     * the `unique(team_id, dm_key)` index dedupes the conversation.
     *
     * Re-opening an existing conversation restores it for everyone in the target
     * set: the initiator's membership is un-hidden and any participant who had
     * left is re-added, so "add someone back" and "reuse the same set" both land
     * in the one canonical channel with its history intact.
     *
     * @param  Collection<int, User>  $participants
     */
    public function openForUsers(Team $team, User $initiator, Collection $participants): Channel
    {
        $participantIds = $this->participantIds($initiator, $participants);
        $isGroup = $participantIds->count() > 2;
        $dmKey = $isGroup
            ? 'g:'.hash('sha256', $participantIds->implode(':'))
            : $participantIds->implode(':');

        return DB::transaction(function () use ($team, $initiator, $dmKey, $participantIds, $isGroup): Channel {
            $existing = $team->channels()->where('dm_key', $dmKey)->first();

            if ($existing !== null) {
                $this->restoreMembers($existing, $initiator, $participantIds);

                return $existing;
            }

            $channel = $team->channels()->create([
                'name' => null,
                'slug' => 'dm-'.Str::lower(Str::random(12)),
                'visibility' => ChannelVisibility::Private,
                'type' => $isGroup ? ChannelType::GroupDirect : ChannelType::Direct,
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
     * Bring an existing conversation back for the target participant set.
     *
     * Re-creates a membership (at the default notification level) for anyone in
     * the set who is not currently a member — a participant who had left, or the
     * newcomers of an add-people flow — and clears the initiator's `hidden_at` so
     * a conversation they had closed returns to their sidebar even before a new
     * message arrives.
     *
     * @param  Collection<int, string>  $participantIds
     */
    private function restoreMembers(Channel $channel, User $initiator, Collection $participantIds): void
    {
        $current = $channel->channelMembers()->pluck('user_id');

        foreach ($participantIds->diff($current) as $userId) {
            $channel->channelMembers()->create([
                'user_id' => $userId,
                'notification_level' => NotificationLevel::All,
            ]);
        }

        $channel->channelMembers()
            ->where('user_id', $initiator->id)
            ->update(['hidden_at' => null]);
    }

    /**
     * The DM's participant UUIDs, de-duplicated and sorted for a canonical key.
     *
     * A self-DM (only the initiator) collapses to a single UUID.
     *
     * @param  Collection<int, User>  $participants
     * @return Collection<int, string>
     */
    private function participantIds(User $initiator, Collection $participants): Collection
    {
        return $participants
            ->merge([$initiator])
            ->pluck('id')
            ->unique()
            ->sort()
            ->values();
    }
}
