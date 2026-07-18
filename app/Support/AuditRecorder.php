<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Models\AuditActivity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes append-only audit entries for admin and moderation actions. The actor,
 * target, and context are always passed explicitly (the acting user differs
 * from the affected entity), so this is safe to resolve anywhere a request has
 * an authenticated actor in hand.
 */
class AuditRecorder
{
    /**
     * Record a single audit entry for an action performed within a workspace.
     *
     * The actor is nullable for platform-initiated actions with no human causer
     * (e.g. a webhook subscription auto-disabled after repeated delivery
     * failures); every user-initiated call still passes the acting user.
     *
     * @param  Model|null  $target  The entity acted upon (channel, message, or
     *                              the affected member), stored as the subject.
     * @param  array<string, mixed>  $context  Extra detail needed to render a
     *                                         human sentence (names, old->new role).
     */
    public function record(
        Team $team,
        ?User $actor,
        AuditAction $action,
        ?Model $target = null,
        array $context = [],
    ): AuditActivity {
        $logger = activity()
            ->useLog('audit')
            ->causedBy($actor)
            ->event($action->value)
            ->withProperties($context)
            ->tap(function (AuditActivity $activity) use ($team): void {
                $activity->team_id = $team->id;
            });

        if ($target instanceof Model) {
            $logger->performedOn($target);
        }

        $activity = $logger->log($action->label());

        assert($activity instanceof AuditActivity);

        return $activity;
    }
}
