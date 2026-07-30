<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Channel;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ChannelContentSummaryData extends Data
{
    public function __construct(
        public int $messageCount,
        public int $fileCount,
        public int $memberCount,
    ) {}

    /**
     * Count what a channel holds, so a destructive action can say what it costs.
     *
     * Feeds both halves of the delete flow: the confirmation dialog's "3,412
     * messages, 88 files, 12 members" before the fact, and the recently-deleted
     * panel's line per channel during the grace window. Messages already deleted
     * individually are excluded — they are tombstones, not content anyone would
     * count as lost — while every attachment row is counted, blob or hotlink.
     */
    public static function forChannel(Channel $channel): self
    {
        return new self(
            messageCount: $channel->messages()->count(),
            fileCount: $channel->attachments()->count(),
            memberCount: $channel->channelMembers()->count(),
        );
    }
}
