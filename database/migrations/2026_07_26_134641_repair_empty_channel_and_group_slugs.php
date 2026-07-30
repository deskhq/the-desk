<?php

use App\Models\Channel;
use App\Models\UserGroup;
use App\Support\NameSlug;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Give a usable slug back to channels and groups left with a blank one.
     *
     * Both derived their slug with Str::slug() alone, which returns an empty
     * string for a name it cannot transliterate (Japanese, Korean, Hebrew,
     * punctuation, emoji). A channel slug is the route key, so such a channel
     * became unreachable with no UI path back to it, and a blank group handle
     * cannot be mentioned (issue #924). Both are now derived through
     * {@see NameSlug}, and this repairs the rows written before that.
     *
     * A no-op on any instance that never hit the bug — which is every fresh
     * install, since neither slug can be blank any more.
     *
     * Both queries drop their model's global scopes. A migration reads the
     * schema as of its own point in history, but the models it borrows are
     * always today's: Channel has since gained soft deletes, whose scope would
     * filter on a `deleted_at` column that does not exist yet when a fresh
     * install replays the migrations in order. Repairing a deleted channel's
     * slug is right anyway — it is restorable, and would come back unreachable.
     */
    public function up(): void
    {
        Channel::withoutGlobalScopes()
            ->whereRaw("trim(slug) = ''")
            ->get()
            ->each(function (Channel $channel): void {
                $channel->slug = $this->availableSlug($channel, Channel::FALLBACK_SLUG);
                $channel->save();
            });

        UserGroup::withoutGlobalScopes()
            ->whereRaw("trim(slug) = ''")
            ->get()
            ->each(function (UserGroup $group): void {
                $group->slug = $this->availableSlug($group, UserGroup::FALLBACK_SLUG);
                $group->save();
            });
    }

    /**
     * Irreversible: which rows held a blank slug is not recoverable, and
     * restoring one would put the record back out of reach anyway.
     */
    public function down(): void
    {
        //
    }

    /**
     * The record's derived slug, stepped past anything already using it.
     *
     * Both tables are unique on (team_id, slug). The derived slug is specific
     * to the name, so a clash needs a sibling already holding exactly that
     * slug — vanishingly unlikely, but a migration that throws would block the
     * upgrade, so it is handled rather than assumed away.
     */
    private function availableSlug(Channel|UserGroup $record, string $fallback): string
    {
        $base = NameSlug::distinct((string) $record->name, $fallback);
        $slug = $base;

        for ($suffix = 1; $this->slugTaken($record, $slug); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    /**
     * Determine whether a sibling in the same team already holds the slug.
     */
    private function slugTaken(Channel|UserGroup $record, string $slug): bool
    {
        return $record->newQueryWithoutScopes()
            ->where('team_id', $record->team_id)
            ->where('slug', $slug)
            ->whereKeyNot($record->getKey())
            ->exists();
    }
};
