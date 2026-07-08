<?php

namespace App\Models;

use Database\Factories\ChannelMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $channel_id
 * @property string $user_id
 * @property string|null $last_read_message_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Channel $channel
 * @property-read User $user
 */
#[Fillable(['channel_id', 'user_id', 'last_read_message_id'])]
class ChannelMember extends Model
{
    /** @use HasFactory<ChannelMemberFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the channel the membership belongs to.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Get the user the membership belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
