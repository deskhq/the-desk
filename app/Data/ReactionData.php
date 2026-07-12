<?php

namespace App\Data;

use App\Models\Message;
use App\Models\MessageReaction;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ReactionData extends Data
{
    /**
     * @param  array<int, MentionData>  $reactors
     */
    public function __construct(
        public string $emoji,
        public int $count,
        public array $reactors,
    ) {}

    /**
     * Aggregate a message's reactions into one entry per distinct emoji.
     *
     * The `reactions` relation (with each row's `user`) should be eager-loaded.
     * Rows are grouped by emoji, preserving the first-reacted order the relation
     * already sorts by, so the client renders stable pills. Each entry carries
     * the emoji, its total count, and the reactor set (`MentionData`) — the
     * client derives "did I react" from whether its own id appears among them,
     * so the summary stays viewer-free and rides the broadcast unchanged.
     *
     * @return array<int, self>
     */
    public static function forMessage(Message $message): array
    {
        return $message->reactions
            ->groupBy('emoji')
            ->map(fn (Collection $group, string $emoji): ReactionData => new self(
                emoji: $emoji,
                count: $group->count(),
                reactors: $group->map(fn (MessageReaction $reaction): MentionData => MentionData::fromUser($reaction->user))->all(),
            ))
            ->values()
            ->all();
    }
}
