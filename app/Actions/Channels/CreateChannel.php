<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Enums\AuditAction;
use App\Enums\ChannelVisibility;
use App\Events\AuditableActionOccurred;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use App\Support\NameSlug;
use Illuminate\Support\Facades\DB;

final readonly class CreateChannel
{
    public function __construct(private JoinChannel $joinChannel) {}

    /**
     * Create a channel in the team and add its creator as a member.
     *
     * Every channel an admin creates is audited. The protected #general is the
     * one exception: it is not created by anyone deciding to, it is bootstrapped
     * with the workspace when its first membership lands, so it would only ever
     * add a row nobody acted on.
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

            if (! $channel->isGeneral()) {
                event(new AuditableActionOccurred($team, $creator, AuditAction::ChannelCreated, $channel, [
                    'channel_name' => $channel->name,
                ]));
            }

            return $channel;
        });
    }
}
