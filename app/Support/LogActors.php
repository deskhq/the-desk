<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The people who appear in an admin log, as the log's actor filter offers them.
 *
 * One facet shared by {@see AuditLog} and {@see SecurityLog}, because it is the
 * same question asked of two different logs: which users does this log actually
 * name, and what are they called. Both used to spell it out — the same distinct
 * pluck, the same `whereIn` by name, the same `{id, name}` map (#1199).
 *
 * It is derived from the log's own scoped query rather than from the team's
 * roster, so the filter only ever offers a name that has something behind it: a
 * member with no entries is not a choice, and neither is one whose rows the log
 * withholds.
 *
 * @template TModel of Model
 */
final readonly class LogActors
{
    /**
     * @param  Builder<TModel>  $log  the log's scoped query, before its filters
     * @param  string  $actorColumn  the column naming the acting user
     */
    public function __construct(
        private Builder $log,
        private string $actorColumn,
    ) {}

    /**
     * The named actors, alphabetically, for the filter dropdown.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function all(): array
    {
        $actorIds = $this->log->distinct()->pluck($this->actorColumn);

        return User::query()
            ->whereIn('id', $actorIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all();
    }
}
