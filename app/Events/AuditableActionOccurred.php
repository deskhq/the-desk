<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\AuditAction;
use App\Listeners\RecordAuditActivity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something worth recording in a workspace's audit log happened. This is the
 * single emission abstraction for the audit half of ADR-0005: the mutation —
 * in its Action — dispatches one of these, and {@see RecordAuditActivity}
 * appends the row. Recording never lives in a controller, so every caller of
 * that Action gets the audit, HTTP or not.
 *
 * Actor and team are carried explicitly rather than read from the live request:
 * the REST API resolves both from the token subject (which may be a bot), and a
 * queued directory sync has no request at all.
 */
class AuditableActionOccurred
{
    use Dispatchable;

    /**
     * @param  User|null  $actor  The acting user, or null for a platform-initiated
     *                            action with no human causer.
     * @param  Model|null  $target  The entity acted upon, stored as the subject.
     * @param  array<string, mixed>  $context  Extra detail needed to render a
     *                                         human sentence (names, old->new role).
     */
    public function __construct(
        public readonly Team $team,
        public readonly ?User $actor,
        public readonly AuditAction $action,
        public readonly ?Model $target = null,
        public readonly array $context = [],
    ) {}
}
