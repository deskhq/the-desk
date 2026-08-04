<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\UnreadCountsData;
use App\Data\UnreadDigestData;
use App\Enums\MessageType;
use App\Enums\NotificationLevel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use stdClass;

/**
 * What the viewer has not read, in the two shapes the shell asks for: per
 * workspace (the rail's dots and the workspace sheet's badges) and per channel
 * (the sidebar's badges).
 *
 * Both come out of **one** grouped query here, so they cannot drift. The
 * per-workspace reading is the per-channel readings summed, which is stronger
 * than two queries agreeing by inspection: a workspace dot that disagreed with
 * the channel rows found inside it would be worse than no dot at all.
 *
 * "Unread" is one rule — every non-deleted, non-system message authored by
 * someone else that lands after the viewer's `last_read_message_id`, a null
 * pointer meaning the channel was never opened. Muting and the notification
 * level are applied here, through {@see NotificationLevel}'s own SQL forms, so
 * suppression stays server-side and the client renders what it is handed.
 *
 * The query is an indexed aggregate over `messages`, not a hydrate-and-serialise
 * of the roster: it returns one row per channel that holds something, whatever
 * the workspace's size, and its cost is one query however many channels the
 * viewer belongs to.
 */
class WorkspaceUnread
{
    /**
     * The viewer's whole unread standing, ready to ship.
     *
     * `$workspace` is the one they are currently looking at, if any: its
     * channels are detailed and its threads consulted. Every workspace they
     * belong to is counted either way, because the rail draws its dots from
     * pages that have no workspace of their own (settings, team admin).
     */
    public static function digest(?User $viewer, ?Team $workspace = null): UnreadDigestData
    {
        if (! $viewer instanceof User) {
            return UnreadDigestData::none();
        }

        $teams = [];
        $channels = [];

        foreach (self::countsByChannel($viewer) as $row) {
            $unread = (int) $row->unread_count;
            $mention = (int) $row->mention_count;

            // A channel can match the aggregate and still report nothing — every
            // message in it a thread-only reply, say. Sparse means sparse.
            if ($unread === 0 && $mention === 0) {
                continue;
            }

            $teamId = (string) $row->team_id;
            $running = $teams[$teamId] ?? new UnreadCountsData(unread: 0, mention: 0);

            $teams[$teamId] = new UnreadCountsData(
                unread: $running->unread + $unread,
                mention: $running->mention + $mention,
            );

            if ($workspace instanceof Team && $teamId === $workspace->id) {
                $channels[(string) $row->channel_id] = new UnreadCountsData($unread, $mention);
            }
        }

        return new UnreadDigestData(
            channels: $channels,
            teams: $teams,
            threads: $workspace instanceof Team && new ThreadInbox($viewer, $workspace)->hasUnread(),
        );
    }

    /**
     * One row per channel the viewer has anything waiting in, across every
     * workspace they belong to.
     *
     * Rides `Message::query()` rather than the query builder so the model's
     * soft-delete scope applies — a deleted message must stop counting — then
     * drops to the base builder because the rows are aggregates, not messages.
     *
     * @return Collection<int, stdClass>
     */
    protected static function countsByChannel(User $viewer): Collection
    {
        return Message::query()
            ->join('channels', 'channels.id', '=', 'messages.channel_id')
            ->join('channel_members', function (JoinClause $join) use ($viewer): void {
                $join->on('channel_members.channel_id', '=', 'channels.id')
                    ->where('channel_members.user_id', '=', $viewer->id);
            })
            // A mention is a row in the pivot for *this* viewer; the left join
            // keeps ordinary messages in the aggregate with a null side.
            ->leftJoin('mentions', function (JoinClause $join) use ($viewer): void {
                $join->on('mentions.message_id', '=', 'messages.id')
                    ->where('mentions.mentioned_user_id', '=', $viewer->id);
            })
            ->whereNull('channels.archived_at')
            ->where('messages.user_id', '!=', $viewer->id)
            // System notices (member joined / left) are ambient: they never
            // badge a channel, so they never badge a workspace either.
            ->whereNotIn('messages.type', MessageType::systemValues())
            ->where(fn ($query) => $query
                ->whereNull('channel_members.last_read_message_id')
                ->orWhereColumn('messages.id', '>', 'channel_members.last_read_message_id'))
            // A channel that alerts on neither reading is silent on both counts,
            // so it is excluded outright rather than case-by-case. The mention
            // reading is the wider of the two, so it is the one that bounds the
            // whole aggregate.
            ->whereRaw(NotificationLevel::alertsOnMentionSql('channel_members'))
            ->groupBy('channels.id', 'channels.team_id')
            ->select('channels.id as channel_id', 'channels.team_id')
            // Thread-only replies live in the thread view and stay out of the
            // plain unread count, and the "mentions" level silences it entirely.
            // Both halves are the owning module's own SQL fragment rather than a
            // copy: both counts are aggregated in this one grouped query, so the
            // unread half has to be a conditional aggregate — the one shape
            // `Message::channelTraffic()` and a `where` cannot express.
            ->selectRaw(
                'sum(case when '.NotificationLevel::alertsOnUnreadSql('channel_members')
                .' and '.Message::channelTrafficSql().' then 1 else 0 end) as unread_count',
            )
            ->selectRaw('sum(case when mentions.message_id is not null then 1 else 0 end) as mention_count')
            ->toBase()
            ->get();
    }
}
