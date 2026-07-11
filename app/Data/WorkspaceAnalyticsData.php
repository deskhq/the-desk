<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The full analytics payload for a workspace over a selected time window: the
 * four headline tiles plus the four charts/rankings the dashboard renders.
 */
#[TypeScript]
class WorkspaceAnalyticsData extends Data
{
    /**
     * @param  array<int, DailyMessageCountData>  $messagesByDay
     * @param  array<int, ChannelActivityData>  $topChannels
     * @param  array<int, MonthlyMemberCountData>  $memberGrowth
     * @param  array<int, ContributorData>  $topContributors
     */
    public function __construct(
        public string $range,
        public int $days,
        public AnalyticsStatData $activeMembers,
        public AnalyticsStatData $messagesPerDay,
        public AnalyticsStatData $messagesSent,
        public AnalyticsStatData $activeChannels,
        #[DataCollectionOf(DailyMessageCountData::class)]
        public array $messagesByDay,
        #[DataCollectionOf(ChannelActivityData::class)]
        public array $topChannels,
        #[DataCollectionOf(MonthlyMemberCountData::class)]
        public array $memberGrowth,
        #[DataCollectionOf(ContributorData::class)]
        public array $topContributors,
    ) {}
}
