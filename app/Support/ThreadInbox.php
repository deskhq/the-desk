<?php

namespace App\Support;

use App\Enums\ThreadInboxFilter;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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

        // A card names the DM it sits in, which is resolved from the membership;
        // batching the page's rosters keeps that off the per-card path.
        DirectMessageRoster::loadForMessages($threads->getCollection());

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
            ->whereIn('channel_id', $this->viewer->memberChannelIds($this->team))
            ->whereNull('thread_root_id')
            ->where('reply_count', '>', 0)
            ->followedBy($this->viewer);
    }
}
