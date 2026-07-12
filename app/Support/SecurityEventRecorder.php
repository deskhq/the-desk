<?php

namespace App\Support;

use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Persists security-relevant account events, capturing the live request's IP
 * and User-Agent. Must be resolved during a real request so the request context
 * is available — never from a queued job.
 */
class SecurityEventRecorder
{
    public function __construct(private readonly Request $request) {}

    /**
     * Record a security event for the given user against the current request.
     */
    public function record(Authenticatable $user, SecurityEventType $type): SecurityEvent
    {
        $userId = $user->getAuthIdentifier();
        $ipAddress = $this->request->ip();
        $userAgent = $this->request->userAgent();

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
