<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Persisting a drag: the ownership-filter-then-reindex walk behind every manual
 * sidebar order.
 *
 * The sidebar lets a user reorder two different things — the channels within a
 * group and their custom sections — and both arrive the same way: the full,
 * ordered list of ids in the group, from a client that can name any id it likes.
 * So both need the same two steps, which is why they share this one walk rather
 * than each spelling it out.
 *
 * 1. **Filter to what the user owns.** The caller supplies a query already
 *    scoped to their own rows; anything the ordered list names outside it is
 *    dropped rather than written.
 * 2. **Reindex in a single statement.** Each surviving row takes its index in
 *    the list as its new position, written as one upsert keyed on the primary
 *    key — the rows are read first, so the insert branch can never be taken and
 *    only `position` is ever assigned. A 40-channel drag used to issue 40
 *    UPDATEs; it now issues one.
 */
final class ManualOrder
{
    /**
     * Assign each of the ordered ids its index as the row's manual position.
     *
     * `$matchColumn` is the column the ids are ids *of*: the row's own key when
     * ordering sections, the `channel_id` of a membership when ordering channels.
     *
     * @param  Builder<covariant Model>  $owned  scoped to the rows the user may reorder
     * @param  list<string>  $orderedIds
     */
    public static function apply(Builder $owned, string $matchColumn, array $orderedIds): void
    {
        // A repeated id is the last index it was given, which is what writing
        // the list front-to-back used to leave behind.
        $positions = array_flip($orderedIds);

        if ($positions === []) {
            return;
        }

        $rows = $owned->whereIn($matchColumn, array_keys($positions))->get();
        $first = $rows->first();

        if (! $first instanceof Model) {
            return;
        }

        $now = now();

        $first->newQuery()->upsert(
            $rows->map(fn (Model $row): array => [
                // The row as it stands, so the upsert's insert branch — which the
                // conflict on an already-read primary key never reaches — is
                // still a valid tuple for any table this is asked to reorder.
                ...$row->getAttributes(),
                'position' => $positions[$row->getAttribute($matchColumn)],
                'updated_at' => $now,
            ])->all(),
            [$first->getKeyName()],
            ['position', 'updated_at'],
        );
    }
}
