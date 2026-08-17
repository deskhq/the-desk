<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\AuditAction;
use App\Models\AuditActivity;
use App\Models\User;
use App\Support\PersistedTimestamp;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A recorded admin/moderation action, as a workspace's audit log renders it.
 *
 * `actorName` is null when the acting user no longer exists, and `description`
 * is a ready-to-render human sentence rather than a template the client has to
 * fill — the context an action was recorded with never leaves the server.
 */
#[TypeScript]
final class AuditEventData extends Data
{
    public function __construct(
        public string $id,
        public string $action,
        public string $label,
        public ?string $actorName,
        public string $description,
        public string $occurredAt,
    ) {}

    /**
     * Build the DTO from a recorded audit entry.
     */
    public static function fromActivity(AuditActivity $activity): self
    {
        $action = AuditAction::from((string) $activity->event);

        /** @var array<string, mixed> $context */
        $context = $activity->properties?->toArray() ?? [];

        /** @var User|null $actor */
        $actor = $activity->causer;

        return new self(
            id: $activity->id,
            action: $action->value,
            label: $action->label(),
            actorName: $actor?->name,
            description: $action->describe($context),
            occurredAt: PersistedTimestamp::of($activity->created_at)->toIso8601String(),
        );
    }
}
