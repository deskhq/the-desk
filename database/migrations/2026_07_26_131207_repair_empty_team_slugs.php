<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Give a usable slug back to teams left with a blank one.
     *
     * Team slugs were derived with Str::slug() alone, which returns an empty
     * string for a name it cannot transliterate (Japanese, Korean, Hebrew,
     * punctuation, emoji). The slug is the route key, so such a team became
     * unreachable and its owner had to repair the row by hand (issue #921).
     * The generator now falls back to a generic base, and re-saving the row
     * runs it: the model guard regenerates a blank slug on save. Soft-deleted
     * teams are included so a later restore does not resurrect the problem.
     *
     * A no-op on any instance that never hit the bug — which is every fresh
     * install, since the generator can no longer produce a blank slug.
     */
    public function up(): void
    {
        Team::withTrashed()
            ->whereRaw("trim(slug) = ''")
            ->get()
            ->each(function (Team $team): void {
                $team->save();
            });
    }

    /**
     * Irreversible: which teams held a blank slug is not recoverable, and
     * putting one back would only make those teams unreachable again.
     */
    public function down(): void
    {
        //
    }
};
