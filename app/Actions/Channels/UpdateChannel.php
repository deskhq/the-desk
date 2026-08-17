<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Enums\MessageType;
use App\Events\ChannelUpdated;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class UpdateChannel
{
    public function __construct(private PostSystemMessage $postSystemMessage) {}

    /**
     * Apply an edit to a channel's own details and announce it.
     *
     * Only the keys present in `$attributes` are touched, so a caller that
     * edits the description alone never clears the topic. The channel's `slug`
     * deliberately does not follow a rename: it is the channel's permalink (and
     * what {@see Channel::isGeneral()} keys the protected #general channel on),
     * so a rename would otherwise break every existing link and quietly strip
     * #general of its protection.
     *
     * A name or topic change leaves a system notice in the timeline, Slack-style,
     * so the channel carries its own history of what it used to be called and be
     * about; a description edit is silent. Either way {@see ChannelUpdated} tells
     * open clients their copy of the details is stale.
     *
     * @param  array{name?: string, topic?: string|null, description?: string|null, is_default?: bool}  $attributes
     */
    public function handle(Channel $channel, User $actor, array $attributes): Channel
    {
        return DB::transaction(function () use ($channel, $actor, $attributes): Channel {
            $renamedTo = array_key_exists('name', $attributes) && $attributes['name'] !== $channel->name
                ? $attributes['name']
                : null;

            $topicChanged = array_key_exists('topic', $attributes) && $attributes['topic'] !== $channel->topic;

            $channel->fill($attributes)->save();

            if ($renamedTo !== null) {
                $this->postSystemMessage->handle($channel, $actor, MessageType::ChannelRenamed, $renamedTo);
            }

            if ($topicChanged) {
                $this->postSystemMessage->handle($channel, $actor, MessageType::TopicChanged, (string) $channel->topic);
            }

            broadcast(new ChannelUpdated($channel));

            return $channel;
        });
    }
}
