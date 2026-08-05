<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A short digest of the rows a roster is built from, used as the invalidation
 * key that roster is shared under.
 *
 * The counterpart to {@see InstanceFingerprint}, for the props no viewer write
 * moves. An instance constant changes when the operator edits `.env`; a roster
 * changes when *somebody else* joins, leaves, uploads an emoji or renames a
 * workspace — so there is no write of the viewer's to hang the invalidation on,
 * and for `customEmojis` and `userGroups` no invalidation trigger existed at
 * all: every consumer of them is read-only. Their trigger is this.
 *
 * **Push for latency, fingerprint for correctness.** Where an Echo event exists
 * it still fires a targeted partial reload for an instant refresh, and that is
 * strictly faster than waiting for a navigation. What the fingerprint adds is
 * that a *missed* event self-heals on the very next one — which is what keeps
 * Echo non-load-bearing on a self-hosted instance, where Reverb is the piece
 * most likely to be misconfigured and a member who joined must not be missing
 * until someone thinks to hard-reload.
 *
 * Three properties are deliberate:
 *
 * 1. **An aggregate, never a hydrate.** Each reading below is one indexed
 *    `count(*)` plus a summed write clock over rows the database never hands
 *    back. At 300 members that is one aggregate replacing a 300-row hydrate and
 *    tens of kilobytes of serialised roster, on every click.
 * 2. **Summed rather than newest.** Eloquent persists these columns at second
 *    resolution, so a `max(updated_at)` is blind to a second write landing in
 *    the same second as the newest one the client was already answered under —
 *    a teammate renaming themselves right after another did. Summing the whole
 *    column instead puts *every* row's write clock in the digest, so any one of
 *    them moving moves the key.
 * 3. **The count sits beside it.** A deletion moves no clock forward at all, so
 *    a revoked emoji or a departed member would otherwise be invisible; the
 *    count is what sees them leave.
 *
 * The last property is that each aggregate reaches wherever its prop reads:
 * `userGroups` carries a membership count that lives on a pivot the group's own
 * row knows nothing about, and the workspace list carries a member count for
 * every workspace in it. Both are folded in, because a fingerprint that
 * under-invalidates is a roster that goes stale and stays stale.
 *
 * The keys these compose are {@see HandleInertiaRequests}'s business, as every
 * other once key is; what lives here is only what the digest is taken over.
 */
final class RosterFingerprint
{
    /**
     * How much of each digest is kept.
     *
     * Carried in a request header once per prop on every navigation, so its
     * length is paid continuously while the saving it buys is paid once —
     * exactly the trade {@see InstanceFingerprint::LENGTH} makes, and the same
     * thirty-two bits. A collision costs one roster reaching the client on its
     * next hard load rather than its next click.
     */
    private const int LENGTH = 8;

    /**
     * The workspace's member roster, as `teamMembers` serialises it.
     *
     * Two write clocks rather than one, because two different rows move it: a
     * member editing their profile writes to `users`, and a member joining
     * writes a membership.
     */
    public static function forTeamMembers(Team $team): string
    {
        return self::digest(
            DB::table('team_members')
                ->join('users', 'users.id', '=', 'team_members.user_id')
                ->where('team_members.team_id', $team->getKey())
                ->selectRaw('count(*) as members')
                ->selectRaw('coalesce(sum(extract(epoch from users.updated_at)), 0) as profiled_at')
                ->selectRaw('coalesce(sum(extract(epoch from team_members.updated_at)), 0) as joined_at')
                ->first()
        );
    }

    /**
     * The workspace's custom emoji, as the shortcode map serialises them.
     */
    public static function forCustomEmojis(Team $team): string
    {
        return self::digest(
            DB::table('custom_emojis')
                ->where('team_id', $team->getKey())
                ->selectRaw('count(*) as emojis')
                ->selectRaw('coalesce(sum(extract(epoch from updated_at)), 0) as changed_at')
                ->first()
        );
    }

    /**
     * The workspace's mentionable user groups, including how many people each
     * holds.
     *
     * The membership half is a left join rather than a second reading: adding
     * someone to a group touches the pivot and nothing else, so a digest over
     * `user_groups` alone would leave the `@` menu quoting a count that is no
     * longer true. Groups with no members still have to be counted, hence the
     * distinct count on the outer side.
     */
    public static function forUserGroups(Team $team): string
    {
        return self::digest(
            DB::table('user_groups')
                ->leftJoin('user_group_user', 'user_group_user.user_group_id', '=', 'user_groups.id')
                ->where('user_groups.team_id', $team->getKey())
                ->selectRaw('count(distinct user_groups.id) as groups')
                ->selectRaw('count(user_group_user.id) as group_members')
                ->selectRaw('coalesce(sum(extract(epoch from user_groups.updated_at)), 0) as changed_at')
                ->selectRaw('coalesce(sum(extract(epoch from user_group_user.updated_at)), 0) as joined_at')
                ->first()
        );
    }

    /**
     * The viewer's workspaces, as the rail's tiles and the workspace sheet list
     * them.
     *
     * Scoped to every membership of every workspace the viewer belongs to,
     * because each tile carries that workspace's size: someone joining a
     * workspace the viewer is merely *in* moves a number they are looking at.
     * Soft-deleted workspaces are excluded to match the relation the prop is
     * built from, so a deleted one leaves the count as it leaves the list.
     *
     * Which workspace is current rides along on its own. It is not a roster
     * change at all — it is a column on the viewer — and without it a switch
     * would be answered under the key the client already holds.
     */
    public static function forUserTeams(User $user): string
    {
        $memberships = DB::table('team_members')
            ->join('teams', 'teams.id', '=', 'team_members.team_id')
            ->whereIn('team_members.team_id', fn (Builder $viewers): Builder => $viewers
                ->select('team_id')
                ->from('team_members')
                ->where('user_id', $user->getKey()))
            ->whereNull('teams.deleted_at')
            ->selectRaw('count(*) as memberships')
            ->selectRaw('coalesce(sum(extract(epoch from team_members.updated_at)), 0) as joined_at')
            ->selectRaw('coalesce(sum(extract(epoch from teams.updated_at)), 0) as changed_at')
            ->first();

        return self::digest([$memberships, $user->current_team_id]);
    }

    /**
     * The digest of one aggregate reading.
     */
    private static function digest(mixed $reading): string
    {
        return substr(hash('xxh128', (string) json_encode($reading)), 0, self::LENGTH);
    }
}
