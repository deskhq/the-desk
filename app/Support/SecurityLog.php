<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\TeamSecurityEventData;
use App\Models\SecurityEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;

/**
 * The read model behind a workspace's security log: what the team's members did
 * with their accounts — signed in, changed a password, added a passkey — newest
 * first, narrowed by type and by actor.
 *
 * Constructed from a team and its two filters, never a `Request`, so both
 * readings are reachable without an HTTP round-trip (ADR-0012). The controller
 * keeps the HTTP glue: it validates the filters and names the props.
 *
 * It is a sibling of {@see AuditLog}, sharing the envelope ({@see SimplePage})
 * and the actor facet ({@see LogActors}). What it does not share is its scope,
 * and that is the whole reason the two are separate read-models rather than one
 * parameterised log: {@see SecurityLog::ofCurrentMembers()} is a domain rule with
 * consequences, and folding it into a generic log would leave it homeless.
 */
final readonly class SecurityLog
{
    public function __construct(
        private Team $team,
        private ?string $type = null,
        private ?string $actor = null,
    ) {}

    /**
     * One page of the team's security events, newest first, as the log renders
     * them.
     */
    public function events(): SimplePage
    {
        $events = $this->ofCurrentMembers()
            ->when($this->type, fn (Builder $query): Builder => $query->where('type', $this->type))
            ->when($this->actor, fn (Builder $query): Builder => $query->where('user_id', $this->actor))
            ->with('user');

        return SimplePage::newestFirst($events, TeamSecurityEventData::fromEvent(...));
    }

    /**
     * The members who appear in this log, for the actor filter.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function actors(): array
    {
        return new LogActors($this->ofCurrentMembers(), 'user_id')->all();
    }

    /**
     * **The membership rule.** A security event is recorded against an account,
     * not a workspace — one login is one row however many teams the person
     * belongs to — so a workspace's view of it is a *live* join to that team's
     * current membership, evaluated on every read.
     *
     * The consequence is deliberate and is what the rule is for: removing
     * someone from the team drops their events from this log immediately, and
     * re-adding them brings the history back intact. An admin's window onto a
     * teammate's account activity lasts exactly as long as the teammate is their
     * teammate, and no copy of it is left behind here when they leave.
     *
     * It is also what {@see TeamSecurityEventData}'s non-nullable `actorName`
     * rests on: the join guarantees the user exists.
     *
     * @return Builder<SecurityEvent>
     */
    private function ofCurrentMembers(): Builder
    {
        return SecurityEvent::query()
            ->whereIn('user_id', $this->team->members()->getQuery()->select('users.id'));
    }
}
