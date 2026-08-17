<?php

declare(strict_types=1);

namespace App\Actions\Channels;

use App\Models\Message;
use App\Models\ThreadRead;
use App\Models\User;

final class MarkThreadRead
{
    /**
     * Advance the user's read pointer to the thread's most recent reply.
     *
     * Pointing at the latest reply id (soft-deleted rows included, so the pointer
     * never lags behind a deleted tail) clears the thread's unread dot. A thread
     * with no replies leaves no pointer to set. This never touches the channel's
     * `last_read_message_id`, so a thread's read state stays independent of the
     * channel's.
     */
    public function handle(Message $root, User $user): void
    {
        $latestReplyId = $root->threadReplies()->withTrashed()->orderByDesc('id')->value('id');

        if ($latestReplyId === null) {
            return;
        }

        ThreadRead::query()->updateOrCreate(
            ['thread_root_id' => $root->id, 'user_id' => $user->id],
            ['last_read_reply_id' => $latestReplyId],
        );
    }
}
