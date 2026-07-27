<?php

namespace App\Actions\Channels;

use App\Events\ReadStateAdvanced;
use App\Models\Message;
use App\Models\Team;
use App\Models\ThreadRead;
use App\Models\User;
use App\Support\ThreadInbox;
use Illuminate\Support\Collection;

class MarkAllThreadsRead
{
    /**
     * Clear the viewer's Threads inbox across one team in a single write.
     *
     * The set is exactly what the panel's "Unread" pill counts — every followed
     * thread holding a reply the viewer has not seen, in a channel they belong to
     * ({@see ThreadInbox} owns that query, so the button can never clear more than
     * the panel showed). Each pointer lands on the thread's most recent reply,
     * soft-deleted rows included, so it never lags behind a deleted tail; this
     * never touches a channel's own `last_read_message_id`.
     *
     * An already-clear inbox writes nothing and stays silent. Otherwise every
     * channel the write touched signals {@see ReadStateAdvanced} to the viewer's
     * other devices, which is how their rail dot clears there too — skipping the
     * device that asked, since it reloads the panel from this request's response.
     */
    public function handle(User $user, Team $team): void
    {
        $pointers = new ThreadInbox($user, $team)->unread()
            ->select('messages.id', 'messages.channel_id')
            // The thread's newest reply, tombstones included, so a deleted tail
            // cannot leave the pointer behind. Ordered rather than aggregated:
            // `max()` over a uuid column is not portable across Postgres versions.
            ->selectRaw('(select r.id from messages r where r.thread_root_id = messages.id order by r.id desc limit 1) as latest_reply_id')
            ->get();

        if ($pointers->isEmpty()) {
            return;
        }

        ThreadRead::query()->upsert(
            $pointers->map(fn (Message $root): array => [
                'thread_root_id' => $root->id,
                'user_id' => $user->id,
                'last_read_reply_id' => $root->getAttribute('latest_reply_id'),
                'created_at' => now(),
                'updated_at' => now(),
            ])->all(),
            ['thread_root_id', 'user_id'],
            ['last_read_reply_id', 'updated_at'],
        );

        $this->announce($user, $pointers);
    }

    /**
     * Tell the viewer's other devices which channels' read state moved.
     *
     * One signal per channel rather than one per thread: the client answers it with
     * a single debounced reload of its badges, so a burst would buy nothing.
     *
     * @param  Collection<int, Message>  $pointers
     */
    private function announce(User $user, Collection $pointers): void
    {
        $pointers->pluck('channel_id')
            ->unique()
            ->each(function (string $channelId) use ($user): void {
                broadcast(new ReadStateAdvanced($user->id, $channelId))->toOthers();
            });
    }
}
