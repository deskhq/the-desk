<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Enums\AuditAction;
use App\Enums\ChannelCreationPolicy;
use App\Enums\ChannelVisibility;
use App\Events\AuditableActionOccurred;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Applies a partial update to a workspace's own settings and records what
 * actually moved.
 *
 * The admin page edits the workspace through several small forms, so only the
 * submitted attributes are applied. Each is audited only when its value really
 * changed — which is why the read and the write share one lock: a concurrent
 * update slipping between them would leave an audit entry naming a value that
 * was never replaced.
 */
class UpdateTeam
{
    /**
     * @param  array<string, mixed>  $attributes  The validated subset to apply.
     */
    public function handle(Team $team, User $actor, array $attributes): Team
    {
        return DB::transaction(function () use ($team, $actor, $attributes): Team {
            $locked = Team::whereKey($team->id)->lockForUpdate()->firstOrFail();

            $before = $locked->only(array_keys($attributes));

            $locked->update($attributes);

            $this->recordChanges($locked, $actor, $before);

            return $locked;
        });
    }

    /**
     * Dispatch an audit event for each workspace attribute the update moved.
     *
     * @param  array<string, mixed>  $before  The submitted attributes as they stood beforehand.
     */
    private function recordChanges(Team $team, User $actor, array $before): void
    {
        if (array_key_exists('name', $before) && $before['name'] !== $team->name) {
            event(new AuditableActionOccurred($team, $actor, AuditAction::TeamRenamed, $team, [
                'old_name' => $before['name'],
                'new_name' => $team->name,
            ]));
        }

        foreach (ChannelVisibility::cases() as $visibility) {
            $column = $visibility->value.'_channel_creation_policy';
            $old = $before[$column] ?? null;
            if (! $old instanceof ChannelCreationPolicy) {
                continue;
            }
            if ($old === $team->creationPolicyFor($visibility)) {
                continue;
            }

            event(new AuditableActionOccurred($team, $actor, AuditAction::ChannelCreationPolicyChanged, $team, [
                'visibility' => $visibility->label(),
                'old_policy' => $old->label(),
                'new_policy' => $team->creationPolicyFor($visibility)->label(),
            ]));
        }
    }
}
