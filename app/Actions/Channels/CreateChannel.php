<?php

namespace App\Actions\Channels;

use App\Enums\ChannelVisibility;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use App\Support\NameSlug;
use Illuminate\Support\Facades\DB;

class CreateChannel
{
    public function __construct(private readonly JoinChannel $joinChannel) {}

    /**
     * Create a channel in the team and add its creator as a member.
     */
    public function handle(
        Team $team,
        string $name,
        ChannelVisibility $visibility,
        User $creator,
        ?string $topic = null,
    ): Channel {
        return DB::transaction(function () use ($team, $name, $visibility, $creator, $topic) {
            $name = ltrim(trim($name), '#');

            $channel = $team->channels()->create([
                'name' => $name,
                'slug' => NameSlug::distinct($name, Channel::FALLBACK_SLUG),
                'visibility' => $visibility,
                'topic' => $topic,
                'created_by' => $creator->id,
            ]);

            // The creator seeds the channel rather than joining it, so no
            // "member joined" notice is posted for them.
            $this->joinChannel->handle($channel, $creator, announce: false);

            return $channel;
        });
    }
}
