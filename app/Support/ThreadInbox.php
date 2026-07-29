<?php

namespace App\Support;

use App\Enums\ThreadInboxFilter;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The read model behind the Threads destination: every thread a viewer follows
 * across one team, newest activity first, with their per-thread unread state.
 *
 * "Follow" is the Slack-style auto-follow rule (authored the root, replied, or
 * were @mentioned), and the channel-id filter is the whole ACL — the id set is
 * exactly the channels the viewer belongs to in this team, so threads from
 * channels they cannot see never leak. Unread state is muted per channel, so a
 * muted channel's threads list without a dot and outside the tally.
 *
 * One place owns that query, so the panel's page, the "Unread" pill's count, the
 * rail's dot and the bulk "mark all read" can never disagree about what the
 * viewer follows.
 */
class ThreadInbox
{
    /**
     * How many cards a page loads. The panel pages older threads in on scroll
     * through Inertia's infinite scroll.
     */
    private const int PAGE_SIZE = 30;

    public function __construct(
        private readonly User $viewer,
        private readonly Team $team,
    ) {}

    /**
     * One page of the inbox, as the cards the panel renders.
     */
    public function page(ThreadInboxFilter $filter): ThreadInboxPage
    {
        $threads = $this->followed()
            ->when(
                $filter === ThreadInboxFilter::Unread,
                fn (Builder $query) => $query->whereThreadUnreadFor($this->viewer),
            )
            ->withThreadReadState($this->viewer)
            ->withMessageDataRelations()
            // The card also names the thread's own channel — that is the
            // ThreadInboxItemData shell around the payload, not part of it.
            ->with('channel')
            ->orderByDesc('last_reply_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PAGE_SIZE);

        $this->loadDirectMessageRosters($threads->getCollection());

        return new ThreadInboxPage($threads, $this->viewer);
    }

    /**
     * How many followed threads hold something the viewer has not read, for the
     * "Unread" pill. Counted server-side because the page is cursor-paginated: a
     * tally derived from the loaded cards would report only what fits on screen.
     */
    public function unreadCount(): int
    {
        return $this->unread()->count();
    }

    /**
     * Whether anything at all is unread, driving the rail's and the tab bar's dot.
     * An `exists` rather than {@see unreadCount()} because this one runs on every
     * workspace request.
     */
    public function hasUnread(): bool
    {
        return $this->unread()->exists();
    }

    /**
     * The followed threads that hold unread replies for the viewer — the exact set
     * the "Unread" pill counts and "Mark all read" clears.
     *
     * @return Builder<Message>
     */
    public function unread(): Builder
    {
        return $this->followed()->whereThreadUnreadFor($this->viewer);
    }

    /**
     * Every thread the viewer follows in this team, before any unread filtering.
     *
     * @return Builder<Message>
     */
    private function followed(): Builder
    {
        return Message::query()
            ->whereIn('channel_id', $this->viewer->visibleChannelIds($this->team))
            ->whereNull('thread_root_id')
            ->where('reply_count', '>', 0)
            ->followedBy($this->viewer);
    }

    /**
     * Load the member rosters of the page's DM channels in one query.
     *
     * A DM stores no name, so naming its card means resolving the viewer's
     * counterpart from the membership. Only DMs need it — eager-loading
     * `channel.members` outright would drag every standard channel's full
     * membership along for a name the channel already stores.
     *
     * @param  Collection<int, Message>  $threads
     */
    private function loadDirectMessageRosters(Collection $threads): void
    {
        $directChannels = $threads
            ->map(fn (Message $message): Channel => $message->channel)
            ->filter(fn (Channel $channel): bool => $channel->isDirectMessage())
            ->unique('id')
            ->values();

        if ($directChannels->isEmpty()) {
            return;
        }

        // Loading through an Eloquent collection batches the rosters into one
        // query; the channels inside are the very instances the page's messages
        // hold, so the rosters land where the DTO reads them.
        new EloquentCollection($directChannels->all())->load('members');
    }
}
