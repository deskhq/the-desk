<?php

declare(strict_types=1);

namespace App\Data;

use App\Jobs\PurgeDeletedChannel;
use App\Models\Channel;
use App\Models\Team;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DeletedChannelData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $deletedAt,
        /** When the scheduled purge will destroy the channel for good. */
        public string $purgeAt,
        public ChannelContentSummaryData $summary,
    ) {}

    /**
     * Build the DTO from a soft-deleted Channel model.
     */
    public static function fromChannel(Channel $channel): self
    {
        return new self(
            id: $channel->id,
            name: (string) $channel->name,
            slug: $channel->slug,
            deletedAt: $channel->deleted_at->toISOString(),
            purgeAt: $channel->deleted_at->addDays(PurgeDeletedChannel::GRACE_WINDOW_DAYS)->toISOString(),
            summary: ChannelContentSummaryData::forChannel($channel),
        );
    }

    /**
     * The workspace's channels still inside their grace window, the ones closest
     * to being purged first — the order the panel is read in, since those are the
     * ones a restore decision cannot wait on.
     *
     * @return array<int, self>
     */
    public static function forTeam(Team $team): array
    {
        return $team->channels()
            ->onlyTrashed()
            ->orderBy('deleted_at')
            ->get()
            ->map(fn (Channel $channel): self => self::fromChannel($channel))
            ->all();
    }
}
