<?php

namespace App\Models;

use Database\Factories\PollFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $message_id
 * @property string $question
 * @property bool $allow_multiple
 * @property bool $is_anonymous
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Message $message
 * @property-read Collection<int, PollOption> $options
 * @property-read Collection<int, PollVote> $votes
 */
#[Fillable(['message_id', 'question', 'allow_multiple', 'is_anonymous', 'closed_at'])]
class Poll extends Model
{
    /** @use HasFactory<PollFactory> */
    use HasFactory, HasUuids;

    /**
     * The longest a poll question — or any single option label — may be. Matches
     * the composition validation, and the `string` columns' implicit 255 cap.
     */
    public const int QUESTION_MAX = 255;

    public const int OPTION_LABEL_MAX = 255;

    /**
     * The inclusive bounds on how many options a poll may carry. The builder
     * enforces them client-side and the create request enforces them server-side.
     */
    public const int MIN_OPTIONS = 2;

    public const int MAX_OPTIONS = 10;

    /**
     * Get the message this poll is the payload of.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Get the poll's options, in the author's entry order.
     *
     * @return HasMany<PollOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Get every vote cast across the poll's options.
     *
     * @return HasManyThrough<PollVote, PollOption, $this>
     */
    public function votes(): HasManyThrough
    {
        return $this->hasManyThrough(PollVote::class, PollOption::class);
    }

    /**
     * Whether the poll still accepts votes (it has not been closed).
     */
    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Whether the poll has been closed and its tally frozen.
     */
    public function isClosed(): bool
    {
        return ! $this->isOpen();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'allow_multiple' => 'bool',
            'is_anonymous' => 'bool',
            'closed_at' => 'datetime',
        ];
    }
}
