<?php

namespace App\Http\Controllers\Channels;

use App\Data\ThreadInboxItemData;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ThreadsController extends Controller
{
    /**
     * How many inbox rows a page loads. The client pages older threads in on
     * scroll via InfiniteScroll.
     */
    private const int PAGE_SIZE = 30;

    /**
     * The current user's Threads inbox: every thread they follow across the
     * team, newest activity first, with per-thread unread state.
     *
     * "Follow" is the Slack-style auto-follow rule (authored the root, replied,
     * or were @mentioned), and the channel-id filter is the whole ACL — the id
     * set is exactly the channels the user belongs to in this team, so threads
     * from channels they cannot see never leak. Unread state is muted per
     * channel, so a muted channel's threads list without a dot.
     */
    public function index(Request $request, Team $team): Response
    {
        $user = $request->user();

        $channelIds = $user->visibleChannelIds($team);

        return Inertia::render('channels/Threads', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'threads' => Inertia::scroll(fn () => Message::query()
                ->whereIn('channel_id', $channelIds)
                ->whereNull('thread_root_id')
                ->where('reply_count', '>', 0)
                ->followedBy($user)
                ->withThreadReadState($user)
                ->withMessageDataRelations()
                // The inbox row also names the message's own channel; that is the
                // ThreadInboxItemData shell around the payload, not part of it.
                ->with('channel')
                ->orderByDesc('last_reply_at')
                ->orderByDesc('id')
                ->cursorPaginate(self::PAGE_SIZE)
                ->through(fn (Message $message) => ThreadInboxItemData::fromMessage($message))),
        ]);
    }
}
