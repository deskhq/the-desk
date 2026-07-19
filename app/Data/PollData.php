<?php

namespace App\Data;

use App\Events\PollVoteChanged;
use App\Models\Message;
use App\Models\Poll;
use App\Models\PollOption;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PollData extends Data
{
    /**
     * @param  array<int, PollOptionData>  $options
     */
    public function __construct(
        public string $id,
        public string $question,
        public bool $allowMultiple,
        public bool $isAnonymous,
        public ?string $closedAt,
        public array $options,
        public int $totalVotes,
        public int $voterCount,
    ) {}

    /**
     * Build the DTO from a Poll model.
     *
     * The poll's `options` (each with its `votes.user`) should be eager-loaded —
     * {@see Message::MESSAGE_DATA_RELATIONS} pulls them through. The
     * payload is viewer-free so it rides the {@see PollVoteChanged}
     * broadcast unchanged: per-option rosters let a public poll's client derive
     * its own selection, while `votedByViewer` (populated only when a `$viewerId`
     * is passed, on a viewer-scoped HTTP load) seeds an anonymous poll's own
     * selection on first render. `closedAt` carries the frozen state, so a close
     * broadcasts through the same event.
     *
     * `totalVotes` is the sum across options; `voterCount` is the distinct number
     * of people who voted (they diverge only for a multiple-choice poll), so the
     * card can render "12 votes" for single choice and "8 people voted" for multi.
     */
    public static function fromPoll(Poll $poll, ?string $viewerId = null): self
    {
        $options = $poll->options;

        return new self(
            id: $poll->id,
            question: $poll->question,
            allowMultiple: $poll->allow_multiple,
            isAnonymous: $poll->is_anonymous,
            closedAt: $poll->closed_at?->toIso8601String(),
            options: $options->map(fn (PollOption $option): PollOptionData => PollOptionData::fromOption($option, $poll->is_anonymous, $viewerId))->all(),
            totalVotes: $options->sum(fn (PollOption $option): int => $option->votes->count()),
            voterCount: $options
                ->flatMap(fn (PollOption $option): iterable => $option->votes->pluck('user_id'))
                ->unique()
                ->count(),
        );
    }
}
