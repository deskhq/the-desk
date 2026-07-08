<?php

namespace App\Actions\Channels;

use App\Enums\ChannelVisibility;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateChannel
{
    public function __construct(private JoinChannel $joinChannel) {}

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
                'slug' => Str::slug($name),
                'visibility' => $visibility,
                'topic' => $topic,
                'created_by' => $creator->id,
            ]);

            $this->joinChannel->handle($channel, $creator);

            return $channel;
        });
    }
}
