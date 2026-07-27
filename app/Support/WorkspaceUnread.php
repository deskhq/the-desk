<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\ChannelData;
use App\Enums\MessageType;
use App\Enums\NotificationLevel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

/**
 * The viewer's unread standing in every workspace they belong to, driving the
 * rail's workspace dots and the workspace sheet's per-workspace badges.
 *
 * One grouped query answers every membership at once: the rail draws N tiles,
 * and a per-team loop would put N queries on the shell's critical path for a
 * signal that is decoration. Muting and the notification level are applied in
 * SQL exactly as {@see ChannelData::fromChannel()} applies them per
 * channel, so a workspace badge can never contradict the channel rows the
 * viewer would find inside it.
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
            ->selectRaw(
                'sum(case when channel_members.notification_level = ? and (messages.thread_root_id is null or messages.sent_to_channel = ?) then 1 else 0 end) as unread_count',
                [NotificationLevel::All->value, true],
            )
            ->selectRaw('sum(case when mentions.message_id is not null then 1 else 0 end) as mention_count')
            ->toBase();
    }
}
