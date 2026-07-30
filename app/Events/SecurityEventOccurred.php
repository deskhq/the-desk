<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\SecurityEventType;
use App\Listeners\RecordSecurityEvents;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something worth recording in a user's security activity happened outside the
 * framework's own auth events. Fortify and Laravel already emit their own
 * events for sign-in, password resets and two-factor changes; this covers the
 * account facts the app raises itself — a password change, a revoked session, a
 * data export, a directory provision.
 *
 * {@see RecordSecurityEvents} records it, stamping the live request's device
 * context if there is one. The event carries none of that itself, so a queued
 * directory sync can dispatch it just as well as a controller.
 */
class SecurityEventOccurred
{
    use Dispatchable;

    public function __construct(
        public readonly Authenticatable $user,
        public readonly SecurityEventType $type,
    ) {}
}
