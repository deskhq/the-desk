<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\AuditEventData;
use App\Models\AuditActivity;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;

/**
 * The read model behind a workspace's audit log: what an admin did in this team,
 * newest first, narrowed by action and by actor.
 *
 * Constructed from a team and its two filters, never a `Request`, so both
 * readings are reachable without an HTTP round-trip (ADR-0012). The controller
 * keeps the HTTP glue: it validates the filters and names the props.
 *
 * It is a sibling of {@see SecurityLog}, not a copy of it. The two share the
 * envelope ({@see SimplePage}) and the actor facet ({@see LogActors}) but keep
 * their own scope, because that is where they genuinely differ: an audit entry
 * carries the team it was recorded against, while a security event is
 * account-level and has to be joined to the membership.
 */
final readonly class AuditLog
{
    public function __construct(
        private Team $team,
        private ?string $action = null,
        private ?string $actor = null,
    ) {}

    /**
     * One page of the team's audit entries, newest first, as the log renders
     * them.
     */
    public function entries(): SimplePage
    {
        $entries = $this->recorded()
            ->when($this->action, fn (Builder $query): Builder => $query->where('event', $this->action))
            ->when($this->actor, fn (Builder $query): Builder => $query->where('causer_id', $this->actor))
            ->with('causer');

        return SimplePage::newestFirst($entries, AuditEventData::fromActivity(...));
    }

    /**
     * The distinct people who appear in this log, for the actor filter.
     *
     * Entries with no human causer — a scheduled sweep disabling a webhook, say —
     * are excluded, since there is nobody to offer as a choice.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function actors(): array
    {
        return new LogActors($this->recorded()->whereNotNull('causer_id'), 'causer_id')->all();
    }

    /**
     * Everything recorded against this team.
     *
     * An audit entry is written with the team it belongs to, so the scope is the
     * column — no join, and no rule about who may still be a member: the log is
     * a record of what happened here, and it does not change when someone leaves.
     *
     * @return Builder<AuditActivity>
     */
    private function recorded(): Builder
    {
        return AuditActivity::query()->where('team_id', $this->team->id);
    }
}
