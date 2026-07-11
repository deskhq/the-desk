<?php

namespace App\Support;

use App\Data\AnalyticsStatData;
use App\Data\ChannelActivityData;
use App\Data\ContributorData;
use App\Data\DailyMessageCountData;
use App\Data\MonthlyMemberCountData;
use App\Data\WorkspaceAnalyticsData;
use App\Enums\AnalyticsRange;
use App\Enums\ChannelType;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates a workspace's activity into the analytics dashboard payload.
 *
 * Every metric is scoped to the team's standard (non-DM) channels and to
 * non-deleted messages. Results are cached per team and range so the dashboard
 * never runs its handful of grouped queries on every request.
 */
class WorkspaceAnalytics
{
    /**
     * How long a computed snapshot stays warm in the cache, in minutes.
     */
    private const int CACHE_TTL_MINUTES = 15;

    /**
     * How many months of cumulative member growth to chart.
     */
    private const int GROWTH_MONTHS = 6;

    /**
     * How many rows the most-active-channels ranking returns.
     */
    private const int TOP_CHANNELS = 6;

    /**
     * How many rows the top-contributors ranking returns.
     */
    private const int TOP_CONTRIBUTORS = 5;

    /**
     * Return the workspace's analytics snapshot for the window, from cache when
     * warm, recomputing (and re-caching) otherwise.
     */
    public function for(Team $team, AnalyticsRange $range): WorkspaceAnalyticsData
    {
        // Cache the primitive array rather than the Data object: the database
        // cache store serializes values, and Spatie Data objects do not survive
        // that round trip. Rehydrating with from() rebuilds the nested DTOs.
        $payload = Cache::remember(
            $this->cacheKey($team, $range),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn (): array => $this->compute($team, $range)->toArray(),
        );

        return WorkspaceAnalyticsData::from($payload);
    }

    /**
     * Drop every cached range for a team, so the next view recomputes.
     */
    public function forget(Team $team): void
    {
        foreach (AnalyticsRange::cases() as $range) {
            Cache::forget($this->cacheKey($team, $range));
        }
    }

    /**
     * The cache key for a team's snapshot in a given window.
     */
    private function cacheKey(Team $team, AnalyticsRange $range): string
    {
        return "team:{$team->id}:analytics:{$range->value}";
    }

    /**
     * Run the aggregation queries and assemble the payload.
     */
    private function compute(Team $team, AnalyticsRange $range): WorkspaceAnalyticsData
    {
        $days = $range->days();
        $end = now();
        $start = $end->copy()->startOfDay()->subDays($days - 1);
        $previousEnd = $start->copy()->subSecond();
        $previousStart = $start->copy()->subDays($days);

        return new WorkspaceAnalyticsData(
            range: $range->value,
            days: $days,
            activeMembers: $this->activeMembers($team, $start, $end, $previousStart, $previousEnd),
            messagesPerDay: $this->messagesPerDay($team, $days, $start, $end, $previousStart, $previousEnd),
            messagesSent: $this->messagesSent($team, $start, $end),
            activeChannels: $this->activeChannels($team, $start, $end, $previousStart, $previousEnd),
            messagesByDay: $this->messagesByDay($team, $start, $days),
            topChannels: $this->topChannels($team, $start, $end),
            memberGrowth: $this->memberGrowth($team, $end),
            topContributors: $this->topContributors($team, $start, $end),
        );
    }

    /**
     * A message query joined to the team's standard channels, excluding DMs and
     * soft-deleted messages. The one place the analytics scope is defined.
     */
    private function messages(Team $team): Builder
    {
        return DB::table('messages')
            ->join('channels', 'channels.id', '=', 'messages.channel_id')
            ->where('channels.team_id', $team->id)
            ->where('channels.type', ChannelType::Standard->value)
            ->whereNull('messages.deleted_at');
    }

    /**
     * Distinct authors this window vs the previous one, against total members.
     */
    private function activeMembers(Team $team, CarbonInterface $start, CarbonInterface $end, CarbonInterface $previousStart, CarbonInterface $previousEnd): AnalyticsStatData
    {
        $current = $this->messages($team)
            ->whereBetween('messages.created_at', [$start, $end])
            ->distinct()
            ->count('messages.user_id');

        $previous = $this->messages($team)
            ->whereBetween('messages.created_at', [$previousStart, $previousEnd])
            ->distinct()
            ->count('messages.user_id');

        return new AnalyticsStatData(
            value: $current,
            total: $team->members()->count(),
            delta: $current - $previous,
        );
    }

    /**
     * Average messages per day this window, with the percentage change in total
     * volume against the previous window (null when there is no baseline).
     */
    private function messagesPerDay(Team $team, int $days, CarbonInterface $start, CarbonInterface $end, CarbonInterface $previousStart, CarbonInterface $previousEnd): AnalyticsStatData
    {
        $current = $this->messages($team)
            ->whereBetween('messages.created_at', [$start, $end])
            ->count();

        $previous = $this->messages($team)
            ->whereBetween('messages.created_at', [$previousStart, $previousEnd])
            ->count();

        return new AnalyticsStatData(
            value: (int) round($current / $days),
            deltaPercent: $previous > 0 ? (int) round((($current - $previous) / $previous) * 100) : null,
        );
    }

    /**
     * Total messages this window, with how many of them were thread replies.
     */
    private function messagesSent(Team $team, CarbonInterface $start, CarbonInterface $end): AnalyticsStatData
    {
        $total = $this->messages($team)
            ->whereBetween('messages.created_at', [$start, $end])
            ->count();

        $inThreads = $this->messages($team)
            ->whereBetween('messages.created_at', [$start, $end])
            ->whereNotNull('messages.thread_root_id')
            ->count();

        return new AnalyticsStatData(
            value: $total,
            secondary: $inThreads,
        );
    }

    /**
     * Channels with activity this window vs the previous one, against the count
     * of live (non-archived) standard channels.
     */
    private function activeChannels(Team $team, CarbonInterface $start, CarbonInterface $end, CarbonInterface $previousStart, CarbonInterface $previousEnd): AnalyticsStatData
    {
        $current = $this->messages($team)
            ->whereBetween('messages.created_at', [$start, $end])
            ->distinct()
            ->count('messages.channel_id');

        $previous = $this->messages($team)
            ->whereBetween('messages.created_at', [$previousStart, $previousEnd])
            ->distinct()
            ->count('messages.channel_id');

        $total = $team->channels()
            ->where('type', ChannelType::Standard->value)
            ->whereNull('archived_at')
            ->count();

        return new AnalyticsStatData(
            value: $current,
            total: $total,
            delta: $current - $previous,
        );
    }

    /**
     * The message count for each day in the window, zero-filled so the bar chart
     * has a bar for every day even when nothing was posted.
     *
     * @return array<int, DailyMessageCountData>
     */
    private function messagesByDay(Team $team, CarbonInterface $start, int $days): array
    {
        $end = $start->copy()->addDays($days);

        $counts = $this->messages($team)
            ->whereBetween('messages.created_at', [$start, $end])
            ->selectRaw("to_char(messages.created_at, 'YYYY-MM-DD') as day, count(*) as total")
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = [];
        $cursor = $start->copy();

        for ($i = 0; $i < $days; $i++) {
            $key = $cursor->format('Y-m-d');
            $series[] = new DailyMessageCountData(
                date: $key,
                count: (int) ($counts[$key] ?? 0),
            );
            $cursor = $cursor->addDay();
        }

        return $series;
    }

    /**
     * The busiest standard channels this window, by message count.
     *
     * @return array<int, ChannelActivityData>
     */
    private function topChannels(Team $team, CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->messages($team)
            ->whereBetween('messages.created_at', [$start, $end])
            ->select('channels.id', 'channels.name', DB::raw('count(*) as total'))
            ->groupBy('channels.id', 'channels.name')
            ->orderByDesc('total')
            ->orderBy('channels.name')
            ->limit(self::TOP_CHANNELS)
            ->get()
            ->map(fn (object $row): ChannelActivityData => new ChannelActivityData(
                id: (string) $row->id,
                name: (string) $row->name,
                count: (int) $row->total,
            ))
            ->all();
    }

    /**
     * The cumulative member total at the end of each of the last months, so the
     * line chart shows growth rather than per-month joins.
     *
     * @return array<int, MonthlyMemberCountData>
     */
    private function memberGrowth(Team $team, CarbonInterface $end): array
    {
        $firstMonth = $end->copy()->startOfMonth()->subMonths(self::GROWTH_MONTHS - 1);

        $baseline = DB::table('team_members')
            ->where('team_id', $team->id)
            ->where('created_at', '<', $firstMonth)
            ->count();

        $monthly = DB::table('team_members')
            ->where('team_id', $team->id)
            ->where('created_at', '>=', $firstMonth)
            ->selectRaw("to_char(created_at, 'YYYY-MM') as ym, count(*) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $growth = [];
        $running = $baseline;
        $cursor = $firstMonth->copy();

        for ($i = 0; $i < self::GROWTH_MONTHS; $i++) {
            $running += (int) ($monthly[$cursor->format('Y-m')] ?? 0);
            $growth[] = new MonthlyMemberCountData(
                month: $cursor->format('Y-m-d'),
                total: $running,
            );
            $cursor = $cursor->addMonth();
        }

        return $growth;
    }

    /**
     * The members who posted the most this window.
     *
     * @return array<int, ContributorData>
     */
    private function topContributors(Team $team, CarbonInterface $start, CarbonInterface $end): array
    {
        return $this->messages($team)
            ->join('users', 'users.id', '=', 'messages.user_id')
            ->whereBetween('messages.created_at', [$start, $end])
            ->select('users.id', 'users.name', DB::raw('count(*) as total'))
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total')
            ->orderBy('users.name')
            ->limit(self::TOP_CONTRIBUTORS)
            ->get()
            ->map(fn (object $row): ContributorData => new ContributorData(
                id: (string) $row->id,
                name: (string) $row->name,
                count: (int) $row->total,
            ))
            ->all();
    }
}
