<?php

namespace App\Support;

use App\Enums\SecurityEventType;
use App\Listeners\RecordSecurityEvents;
use App\Models\SecurityEvent;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Persists security-relevant account events. The device context is passed in
 * explicitly rather than read from a live request, so this resolves anywhere —
 * a controller, a queued directory sync, a console command. Capturing that
 * context is {@see RecordSecurityEvents}' job, since only it knows whether an
 * HTTP request is behind the event.
 */
class SecurityEventRecorder
{
    /**
     * Record a security event for the given user against the given device.
     *
     * @param  string|null  $ipAddress  The originating IP, or null when the event
     *                                  has no request behind it.
     * @param  string|null  $userAgent  The originating User-Agent, on the same terms.
     */
    public function record(
        Authenticatable $user,
        SecurityEventType $type,
        ?string $ipAddress,
        ?string $userAgent,
    ): SecurityEvent {
        $userId = $user->getAuthIdentifier();

        return SecurityEvent::create([
            'user_id' => $userId,
            'type' => $type,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'is_new_device' => $type === SecurityEventType::LoggedIn
                && $this->isNewDevice($userId, $ipAddress, $userAgent),
        ]);
    }

    /**
     * Determine whether this IP and User-Agent are new for the user's sign-ins.
     */
    private function isNewDevice(mixed $userId, ?string $ipAddress, ?string $userAgent): bool
    {
        return ! SecurityEvent::query()
            ->where('user_id', $userId)
            ->where('type', SecurityEventType::LoggedIn)
            ->where('ip_address', $ipAddress)
            ->where('user_agent', $userAgent)
            ->exists();
    }
}
