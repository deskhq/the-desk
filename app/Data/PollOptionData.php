<?php

namespace App\Data;

use App\Models\PollOption;
use App\Models\PollVote;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class PollOptionData extends Data
{
    /**
     * @param  array<int, MentionData>|null  $voters
     */
    public function __construct(
        public string $id,
        public string $label,
        public int $position,
        public int $voteCount,
        public ?array $voters,
        public bool $votedByViewer,
    ) {}

    /**
     * Build the DTO for one poll option from its eager-loaded votes.
     *
     * The option's `votes` (each with its `user`) should be eager-loaded. The
     * `voters` roster is the public counterpart of a reaction's reactor set — the
     * client derives "did I vote this" from whether its own id appears among them.
     * An anonymous poll hides the roster entirely (`voters` is null), so
     * `votedByViewer` carries the viewer's own selection instead: it is populated
     * only when built with a `$viewerId` (a viewer-scoped HTTP load) and is false
     * on the viewer-free broadcast, where the client preserves its local selection.
     */
    public static function fromOption(PollOption $option, bool $isAnonymous, ?string $viewerId): self
    {
        $votes = $option->votes;

        return new self(
            id: $option->id,
            label: $option->label,
            position: $option->position,
            voteCount: $votes->count(),
            voters: $isAnonymous
                ? null
                : $votes->map(fn (PollVote $vote): MentionData => MentionData::fromUser($vote->user))->all(),
            votedByViewer: $viewerId !== null && $votes->contains(fn (PollVote $vote): bool => $vote->user_id === $viewerId),
        );
    }
}
