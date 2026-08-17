<?php

declare(strict_types=1);

namespace App\Support;

use App\Events\UserProfileUpdated;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * The walk every scheduled expiry sweeper runs: cursor over the rows a query
 * has narrowed to the lapsed ones, act on each, and report how many were
 * actually swept.
 *
 * The scheduled Actions stay one per concern — each keeps its own name,
 * description and `withoutOverlapping()` registration in `routes/console.php`,
 * and each still returns its own `int` count — but the walk itself, and the two
 * shapes it is walked in, live here rather than being re-typed per sweeper:
 *
 * - **Purging an expired export** deletes the file before the row, so nothing
 *   is orphaned on the private disk once the download link stops working.
 * - **Clearing a lapsed profile instant** is a compare-and-swap: the cursor can
 *   hand back a user who has since set a *fresh* instant, so each clear is
 *   conditional on the column still holding the lapsed value this pass read. A
 *   value replaced in that window is left alone, neither counted nor broadcast,
 *   rather than being wiped moments after the user set it.
 */
final class ExpirySweep
{
    /**
     * Walk the lapsed rows one at a time, counting the passes that reported
     * work done.
     *
     * @template TRow of Model
     *
     * @param  Builder<TRow>  $lapsed  a query already narrowed to the rows that have expired
     * @param  Closure(TRow): bool  $onEach  true when the row was swept, false when it was left alone
     * @return int the number of rows swept
     */
    public static function sweep(Builder $lapsed, Closure $onEach): int
    {
        $swept = 0;

        foreach ($lapsed->cursor() as $row) {
            if ($onEach($row)) {
                $swept++;
            }
        }

        return $swept;
    }

    /**
     * Delete the exports whose download window has closed, removing the file on
     * the private disk before the row.
     *
     * Pending and failed exports carry no `expires_at`, so only ready-then-
     * expired ones are swept; the file guard covers the row that never produced
     * one.
     *
     * @template TExport of Model
     *
     * @param  Builder<TExport>  $exports  every export of one kind
     * @param  string  $disk  the private disk the files were written to
     * @return int the number of exports purged
     */
    public static function purgeExpiredExports(Builder $exports, string $disk): int
    {
        $files = Storage::disk($disk);

        return self::sweep(
            $exports->whereNotNull('expires_at')->where('expires_at', '<', now()),
            function (Model $export) use ($files): bool {
                $path = $export->getAttribute('path');

                if (is_string($path)) {
                    $files->delete($path);
                }

                $export->delete();

                return true;
            },
        );
    }

    /**
     * Null out every profile instant in `$column` that has passed, and
     * broadcast each clear so teammates' open clients repaint without a reload.
     *
     * This is the eager half of expiry: reads already treat a lapsed instant as
     * over, so the sweep is what makes the lapse *propagate* — nothing else
     * would tell a teammate sitting on an idle page.
     *
     * @param  Builder<User>  $users  every user, or a narrower set the caller has already scoped
     * @param  string  $column  the instant compared against now, swapped on, and always cleared
     * @param  array<int, string>  $alsoCleared  the columns nulled alongside it, if any
     * @return int the number of users cleared
     */
    public static function clearLapsedProfileInstant(Builder $users, string $column, array $alsoCleared = []): int
    {
        return self::sweep(
            $users->whereNotNull($column)->where($column, '<=', now()),
            function (User $user) use ($column, $alsoCleared): bool {
                $cleared = User::query()
                    ->whereKey($user->getKey())
                    ->where($column, $user->getAttribute($column))
                    ->update(array_fill_keys([$column, ...$alsoCleared], null));

                if ($cleared === 0) {
                    return false;
                }

                event(new UserProfileUpdated($user->refresh()));

                return true;
            },
        );
    }
}
