<?php

namespace App\Data;

use App\Enums\AuditAction;
use App\Models\AuditActivity;
use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class AuditEventData extends Data
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
            occurredAt: $activity->created_at->toIso8601String(),
        );
    }
}
