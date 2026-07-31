<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\ChannelData;
use App\Enums\MessageType;
use App\Enums\NotificationLevel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

/**
 * What the viewer has not read, in the two shapes the shell asks for: per
 * workspace (the rail's dots and the workspace sheet's badges) and per channel
 * (the sidebar's badges).
 *
 * Both live here so they cannot drift. "Unread" is one rule — every non-deleted,
 * non-system message authored by someone else that lands after the viewer's
 * `last_read_message_id`, a null pointer meaning the channel was never opened —
 * and a workspace dot that disagreed with the channel rows found inside it would
 * be worse than no dot at all. Muting and the notification level are applied in
 * SQL exactly as {@see ChannelData::fromChannel()} applies them per channel.
 */
class WorkspaceUnread
{
    /**
     * Unread and mention counts per team id, for the teams that have any. A team
     * with nothing new is simply absent, so callers default it to zero.
     *
     * @return array<string, array{unread: int, mention: int}>
     */
    public static function forUser(User $user): array
    {
        return self::query($user)
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->team_id => [
                    'unread' => (int) $row->unread_count,
                    'mention' => (int) $row->mention_count,
                ],
            ])
            ->all();
    }

    /**
     * A correlated sub-query counting one channel's messages the viewer has not
     * yet read, for the sidebar's per-channel badges.
     *
     * Correlated against the outer sidebar query's `channels` and
     * `channel_members` rows, so a single query fills every channel's badge
     * without an N+1. Unlike {@see self::forUser()} it applies no muting: the
     * sidebar ships the raw counts and {@see ChannelData::fromChannel()}
     * suppresses the badge, because a muted channel still has to know it holds
     * something new.
     *
     * @return EloquentBuilder<Message>
     */
    public static function forChannelsOf(User $user): EloquentBuilder
    {
        return Message::query()
            ->selectRaw('count(*)')
            ->whereColumn('messages.channel_id', 'channels.id')
            ->where('messages.user_id', '!=', $user->id)
            // System notices (member joined / left) are ambient: they never
            // advance the unread badge or raise a mention, so they are excluded
            // from both the unread and mention counts. Every other type counts —
            // a poll is user-authored and badges the channel like a message.
            ->whereNotIn('messages.type', MessageType::systemValues())
            ->where(fn (EloquentBuilder $query) => $query
                ->whereNull('channel_members.last_read_message_id')
                ->orWhereColumn('messages.id', '>', 'channel_members.last_read_message_id'));
    }

    /**
     * The grouped query behind {@see self::forUser()}.
     *
     * Rides `Message::query()` rather than the query builder so the model's
     * soft-delete scope applies — a deleted message must stop counting — then
     * drops to the base builder because the rows are aggregates, not messages.
     */
    protected static function query(User $user): QueryBuilder
    {
        return Message::query()
            ->join('channels', 'channels.id', '=', 'messages.channel_id')
            ->join('channel_members', function (JoinClause $join) use ($user): void {
                $join->on('channel_members.channel_id', '=', 'channels.id')
                    ->where('channel_members.user_id', '=', $user->id);
            })
            // A mention is a row in the pivot for *this* viewer; the left join
            // keeps ordinary messages in the aggregate with a null side.
            ->leftJoin('mentions', function (JoinClause $join) use ($user): void {
                $join->on('mentions.message_id', '=', 'messages.id')
                    ->where('mentions.mentioned_user_id', '=', $user->id);
            })
            ->whereNull('channels.archived_at')
            ->where('messages.user_id', '!=', $user->id)
            // System notices (member joined / left) are ambient: they never
            // badge a channel, so they never badge a workspace either.
            ->whereNotIn('messages.type', MessageType::systemValues())
            ->where(fn ($query) => $query
                ->whereNull('channel_members.last_read_message_id')
                ->orWhereColumn('messages.id', '>', 'channel_members.last_read_message_id'))
            // A muted channel — or one set to "nothing" — is silent on both
            // counts, so it is excluded outright rather than case-by-case.
            ->where('channel_members.muted', false)
            ->where('channel_members.notification_level', '!=', NotificationLevel::Nothing->value)
            ->groupBy('channels.team_id')
            ->select('channels.team_id')
            // Thread-only replies live in the thread view and stay out of the
            // plain unread count, and the "mentions" level silences it entirely.
            // The channel-traffic half is {@see Message::channelTrafficSql()}
            // rather than a copy: both counts are aggregated in this one grouped
            // query, so the unread half has to be a conditional aggregate, which
            // is the one shape the scope cannot express.
            ->selectRaw(
                'sum(case when channel_members.notification_level = ? and '.Message::channelTrafficSql().' then 1 else 0 end) as unread_count',
                [NotificationLevel::All->value],
            )
            ->selectRaw('sum(case when mentions.message_id is not null then 1 else 0 end) as mention_count')
            ->toBase();
    }
}
