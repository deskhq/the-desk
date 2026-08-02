<?php

namespace App\Data;

use App\Models\SecurityEvent;
use App\Support\UserAgentParser;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A recorded security-relevant account event, as the Security settings page
 * renders it. `isNewDevice` flags a sign-in from an IP and browser pairing not
 * seen on a prior sign-in, which is what the page draws attention to.
 */
#[TypeScript]
class SecurityEventData extends Data
{
    public function __construct(
        public string $id,
        public string $type,
        public string $label,
        public ?string $ipAddress,
        public string $browser,
        public string $platform,
        public bool $isNewDevice,
        public string $occurredAt,
    ) {}

    /**
     * Build the DTO from a recorded security event.
     */
    public static function fromEvent(SecurityEvent $event): self
    {
        $agent = UserAgentParser::parse($event->user_agent);

        return new self(
            id: $event->id,
            type: $event->type->value,
            label: $event->type->label(),
            ipAddress: $event->ip_address,
            browser: $agent['browser'],
            platform: $agent['platform'],
            isNewDevice: $event->is_new_device,
            occurredAt: $event->created_at->toIso8601String(),
        );
    }
}
