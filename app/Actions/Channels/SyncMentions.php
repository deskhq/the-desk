<?php

namespace App\Actions\Channels;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Support\InlineMarkdown;

class SyncMentions
{
    /**
     * The composer inserts a `@[Display Name](user-id)` token for every member it
     * resolves, and a `@[handle](group:group-id)` token for every user group;
     * this captures the optional type prefix and the id, so the parser can
     * round-trip a token back to a member or a group unambiguously, regardless
     * of display-name clashes. The prefix-less form is the original user token
     * and stays valid, so bodies written before groups existed still resolve.
     */
    private const string TOKEN_PATTERN = '/@\[[^\]]+\]\((group:)?([0-9a-fA-F-]{36})\)/';

    /**
     * Parse the message body and reconcile its mention rows.
     *
     * Only tokens that resolve to an actual member of the channel's team are
     * kept; unknown ids and non-members are ignored. A group token is expanded
     * at post time into one row per member, so a group mention is indistinguish-
     * able downstream from being named individually — it drives the badge, the
     * chime, thread auto-follow, and the `mentions` notification level alike.
     * Because this uses `sync`, an edit adds newly-mentioned members and drops
     * any no longer referenced.
     */
    public function handle(Channel $channel, Message $message): void
    {
        // A mention token inside an inline `code` span renders inert on the
        // client, so it must not notify either; mask code spans before matching.
        preg_match_all(
            self::TOKEN_PATTERN,
            InlineMarkdown::maskInlineCode($message->body),
            $matches,
            PREG_SET_ORDER,
        );

        $userIds = [];
        $groupIds = [];

        foreach ($matches as $match) {
            if ($match[1] === '') {
                $userIds[] = $match[2];

                continue;
            }

            $groupIds[] = $match[2];
        }

        $message->mentionedUsers()->sync(array_values(array_unique(array_merge(
            $this->resolveMemberIds($channel, $userIds),
            $this->expandGroups($channel, $message, $groupIds),
        ))));
    }

    /**
     * Resolve the distinct team-member user ids referenced by user tokens.
     *
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    private function resolveMemberIds(Channel $channel, array $ids): array
    {
        $ids = array_unique($ids);

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('users.id', $ids)
            ->whereHas('teams', fn ($query) => $query->whereKey($channel->team_id))
            ->pluck('users.id')
            ->all();
    }

    /**
     * Expand the referenced groups into the team-member ids they cover.
     *
     * Only groups belonging to the channel's own team count, so a token carrying
     * another workspace's group id is inert. The poster is excluded: mentioning
     * a group you belong to should not ping you. Membership is read here, at
     * post time — a later roster change never retroactively notifies.
     *
     * @param  array<int, string>  $groupIds
     * @return array<int, string>
     */
    private function expandGroups(Channel $channel, Message $message, array $groupIds): array
    {
        $groupIds = array_unique($groupIds);

        if ($groupIds === []) {
            return [];
        }

        return User::query()
            ->whereHas('userGroups', fn ($query) => $query
                ->whereIn('user_groups.id', $groupIds)
                ->where('user_groups.team_id', $channel->team_id))
            ->whereHas('teams', fn ($query) => $query->whereKey($channel->team_id))
            ->whereKeyNot($message->user_id)
            ->pluck('users.id')
            ->all();
    }
}
