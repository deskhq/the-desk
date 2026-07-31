<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\MessageReminderData;
use App\Enums\MessageReminderStatus;
use App\Models\MessageReminder;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The read model behind the workspace's reminder surfaces: the viewer's
 * reminders on messages in one team, soonest first.
 *
 * Pending reminders feed the "Reminders" list and its sidebar count; fired ones
 * drive the in-app nudges. Both shapes come from here so the list and the nudge
 * can never disagree about what is owed.
 *
 * Visibility is re-checked on every read with the same `view` gate the write
 * path uses, because access can be revoked long after the reminder was set. A
 * row whose channel is no longer viewable is redacted rather than dropped, so
 * the owner can still clear it and regaining access restores it intact.
 */
final readonly class SidebarReminders
{
    public function __construct(
        private User $viewer,
        private Team $team,
    ) {}

    /**
     * The viewer's reminders in this team with the given status, ordered by due
     * time, each carrying whether its channel is still viewable.
     *
     * The message, its author, and its channel + team are eager-loaded so each
     * row renders a quote and a working link back. The gate verdict is memoised
     * per channel — several reminders often share one.
     *
     * @return array<int, MessageReminderData>
     */
    public function withStatus(MessageReminderStatus $status): array
    {
        $reminders = MessageReminder::query()
            ->where('user_id', $this->viewer->id)
            ->where('status', $status)
            ->whereHas('message.channel', fn (Builder $query) => $query->where('team_id', $this->team->id))
            ->with(['message.user', 'message.channel.team'])
            ->oldest('remind_at')
            ->get();

        /** @var array<string, bool> $viewable */
        $viewable = [];

        return $reminders->map(function (MessageReminder $reminder) use (&$viewable): MessageReminderData {
            $channel = $reminder->message->channel;
            $viewable[$channel->id] ??= Gate::forUser($this->viewer)->allows('view', $channel);

            return MessageReminderData::fromMessageReminder($reminder, $viewable[$channel->id]);
        })->all();
    }
}
