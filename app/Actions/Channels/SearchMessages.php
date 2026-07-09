<?php

namespace App\Actions\Channels;

use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class SearchMessages
{
    /**
     * The maximum number of message matches returned for a single search.
     */
    private const RESULT_LIMIT = 50;

    /**
     * Full-text search a team's messages, ACL-filtered to the user's channels.
     *
     * The `channel_id` filter is the whole ACL: the id set is exactly the
     * channels the user belongs to within this team, so it enforces both team
     * scoping and membership, and messages from private channels the user cannot
     * see never leak. A blank query, or a user who belongs to no channel in the
     * team, yields no matches without touching the search engine. Relations are
     * eager-loaded after the search (not via a `query()` callback, which the
     * collection engine treats as a signal to skip the ACL filter entirely).
     *
     * @return Collection<int, Message>
     */
    public function handle(User $user, Team $team, string $query): Collection
    {
        $query = trim($query);

        $channelIds = $user->channels()
            ->where('channels.team_id', $team->id)
            ->pluck('channels.id')
            ->all();

        if ($query === '' || $channelIds === []) {
            return new Collection;
        }

        return Message::search($query)
            ->whereIn('channel_id', $channelIds)
            ->take(self::RESULT_LIMIT)
            ->get()
            ->load(['user', 'channel', 'mentionedUsers', 'replyTo.user', 'replyTo.mentionedUsers']);
    }
}
