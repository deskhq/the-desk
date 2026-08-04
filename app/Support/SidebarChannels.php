<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\ChannelData;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * The read model behind the workspace sidebar's channel list: every channel the
 * viewer belongs to in one team, with the per-channel state the rows render from
 * — mute and notification level, star, section, manual position, and whether a
 * draft is waiting.
 *
 * One query fills all of it. What is *unread* in each channel is deliberately
 * not here: it is the one thing that moves on every message anyone sends, and it
 * rides its own prop ({@see WorkspaceUnread}) so this list does not have to be
 * rebuilt to move a badge.
 *
 * Direct messages are the one place the list is not simply "what you belong to":
 * a DM exists from the moment either side opens it, so listing every membership
 * would show a recipient a conversation nobody has said anything in yet. The
 * listing rule lives in {@see self::listsDirectMessage()}.
 */
final readonly class SidebarChannels
{
    public function __construct(
        private User $viewer,
        private Team $team,
    ) {}

    /**
     * The viewer's channels in this team, ordered as the sidebar renders them.
     *
     * `$activeChannel` is the channel the viewer is currently looking at, if
     * any: it is always listed, so navigating straight into a brand-new DM does
     * not leave the sidebar without the row you are standing in.
     *
     * @return array<int, ChannelData>
     */
    public function forSidebar(?Channel $activeChannel = null): array
    {
        $channels = $this->query()->get()->filter(
            // Standard channels always list; a DM has to earn its row.
            fn (Channel $channel): bool => ! $channel->isDirectMessage()
                || $this->listsDirectMessage($channel, $activeChannel?->getKey()),
        )->values();

        // The listed DMs are named from their rosters, so they are loaded here
        // as one batch — before the rows are mapped, not once per row.
        DirectMessageRoster::load($channels);

        return $channels
            ->map(fn (Channel $channel): ChannelData => ChannelData::fromChannel($channel, $this->viewer))
            ->all();
    }

    /**
     * The single query behind the list: the viewer's memberships in this team,
     * with every per-channel column and count the rows read.
     *
     * @return BelongsToMany<Channel, User>
     */
    private function query(): BelongsToMany
    {
        return $this->viewer->channels()
            ->where('channels.team_id', $this->team->id)
            ->whereNull('channels.archived_at')
            ->select('channels.*')
            // Every column of the membership row, from its one declaration, so a
            // column added there is one the sidebar can already see. The draft is
            // the single exclusion — the flag below stands in for it, for the
            // reason given there.
            ->addSelect(array_map(
                fn (string $column): string => 'channel_members.'.$column,
                array_values(array_diff(ChannelMember::PIVOT_COLUMNS, ['draft'])),
            ))
            // The channel's latest message time drives the "Direct messages" group
            // ordering (recent activity first) and, being null when a DM has no
            // messages yet, the listing predicate that hides an empty DM from its
            // recipient below.
            ->selectSub(
                Message::query()->selectRaw('max(messages.created_at)')->whereColumn('messages.channel_id', 'channels.id'),
                'last_message_at'
            )
            // Only the presence of a draft drives the sidebar cue; the draft text
            // itself is shipped solely to the open channel, so keep it out of the
            // sidebar payload and expose a 1/0 flag instead (an integer, not a
            // driver-specific boolean, so the DTO's cast reads it reliably).
            ->selectRaw("case when channel_members.draft is not null and channel_members.draft != '' then 1 else 0 end as has_draft")
            // Manual order within each sidebar group first, then alphabetical as a
            // stable tiebreak for channels the user has never reordered.
            ->orderBy('channel_members.position')
            ->orderBy('channels.name');
    }

    /**
     * Whether a direct message has enough standing to list.
     *
     * A DM lists once it has real activity: it has at least one message, or the
     * viewer created it (the initiator navigated straight into it), or the viewer
     * is currently viewing it. An empty DM the recipient was never messaged in
     * therefore stays hidden for them. A DM the viewer closed stays out until a
     * message arrives after the close instant — that check wins even over the
     * created/active overrides, so closing removes the row immediately.
     * `directParticipantFor` / ordering are then applied client-side.
     */
    private function listsDirectMessage(Channel $channel, ?string $activeChannelId): bool
    {
        if ($this->isClosed($channel)) {
            return false;
        }

        if ($channel->getAttribute('last_message_at') !== null) {
            return true;
        }

        if ($channel->created_by === $this->viewer->id) {
            return true;
        }

        return $channel->getKey() === $activeChannelId;
    }

    /**
     * Whether the viewer has closed (hidden) the direct message and no message has
     * arrived since. A reply after the close instant re-surfaces the DM without
     * any write on the message path, so the check compares the two timestamps.
     */
    private function isClosed(Channel $channel): bool
    {
        $hiddenAt = $channel->getAttribute('hidden_at');

        if ($hiddenAt === null) {
            return false;
        }

        $lastMessageAt = $channel->getAttribute('last_message_at');

        return $lastMessageAt === null
            || Carbon::parse($lastMessageAt)->lessThanOrEqualTo(Carbon::parse($hiddenAt));
    }
}
