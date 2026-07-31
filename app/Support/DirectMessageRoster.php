<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Channel;
use App\Models\Message;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The membership a direct message has to be read through before it can be named.
 *
 * A DM stores no name: what it is called, who its avatar stack shows, and which
 * counterpart drives presence are all resolved from its members, relative to the
 * viewer. That makes naming a *page* of DMs an N+1 by default — every consumer
 * that walked its rows one at a time paid one to two queries per row.
 *
 * This is the one place that answers it for a whole page at once, so the three
 * consumers that name DMs — the sidebar, the thread inbox, and search hits —
 * share a single batched load instead of three implementations. Load the page's
 * rosters here, then let {@see Channel::displayNameFor()} and
 * {@see Channel::directParticipantFor()} read them off the loaded relation.
 *
 * Only DMs are loaded: eager-loading `members` outright would drag every
 * standard channel's full membership along for a name the channel already
 * stores. It does not belong to any one consumer — parking it in the worst of
 * them would leave the other two re-deriving (ADR-0011).
 */
final class DirectMessageRoster
{
    /**
     * Load the member rosters of the direct messages among these channels, in
     * one query.
     *
     * The channels are loaded in place, so the rosters land on the very
     * instances the caller is about to map — nothing is returned.
     *
     * @param  iterable<int, Channel>  $channels
     */
    public static function load(iterable $channels): void
    {
        $directChannels = new Collection($channels)
            ->filter(fn (Channel $channel): bool => $channel->isDirectMessage())
            ->unique('id')
            ->values();

        if ($directChannels->isEmpty()) {
            return;
        }

        // Loading through an Eloquent collection batches the rosters into one
        // query, and the models inside are the caller's own instances.
        new EloquentCollection($directChannels->all())->load('members');
    }

    /**
     * Load the rosters of the direct messages these messages were posted in.
     *
     * Both message-shaped consumers — the thread inbox and the search hits —
     * name the channel each of their rows sits in, and reach it through the
     * already eager-loaded `channel` relation.
     *
     * @param  iterable<int, Message>  $messages
     */
    public static function loadForMessages(iterable $messages): void
    {
        self::load(new Collection($messages)->map(fn (Message $message): Channel => $message->channel));
    }
}
