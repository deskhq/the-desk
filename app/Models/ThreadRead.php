<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ThreadReadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's read pointer within a single thread, tracked independently of the
 * channel's `last_read_message_id`. Marking the channel read never advances it,
 * and advancing it never touches the channel pointer.
 *
 * @property string $id
 * @property string $thread_root_id
 * @property string $user_id
 * @property string|null $last_read_reply_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Message $root
 * @property-read User $user
 */
#[Fillable(['thread_root_id', 'user_id', 'last_read_reply_id'])]
final class ThreadRead extends Model
{
    /** @use HasFactory<ThreadReadFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the root message whose thread this pointer tracks.
     *
     * @return BelongsTo<Message, $this>
     */
    public function root(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'thread_root_id')->withTrashed();
    }

    /**
     * Get the user the read pointer belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
