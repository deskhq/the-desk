<?php

use App\Actions\Sso\SetSsoUserActivation;
use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\SessionRegistry;

function activation(): SetSsoUserActivation
{
    return app(SetSsoUserActivation::class);
}

test('deactivating stamps deactivated_at and revokes every session', function (): void {
    $user = User::factory()->create();
    app(SessionRegistry::class)->record($user->id, 'session-a', '203.0.113.1', 'Chrome', now()->timestamp);

    activation()->deactivate($user);

    expect($user->fresh()->isDeactivated())->toBeTrue()
        ->and(app(SessionRegistry::class)->all($user->id))->toBe([]);
});

test('deactivating records an account deactivated security event', function (): void {
    $user = User::factory()->create();

    activation()->deactivate($user);

    expect(SecurityEvent::query()->where('user_id', $user->id)->where('type', SecurityEventType::AccountDeactivated)->count())->toBe(1);
});

test('reactivating records an account reactivated security event', function (): void {
    $user = User::factory()->create(['deactivated_at' => now()]);

    activation()->reactivate($user);

    expect(SecurityEvent::query()->where('user_id', $user->id)->where('type', SecurityEventType::AccountReactivated)->count())->toBe(1);
});

test('a no-op deactivation records no security event', function (): void {
    $user = User::factory()->create(['deactivated_at' => now()->subDay()]);

    activation()->deactivate($user);

    expect(SecurityEvent::query()->where('type', SecurityEventType::AccountDeactivated)->count())->toBe(0);
});

test('deactivating an already-deactivated account keeps the original timestamp', function (): void {
    $deactivatedAt = now()->subDay();
    $user = User::factory()->create(['deactivated_at' => $deactivatedAt]);

    activation()->deactivate($user);

    expect($user->fresh()->deactivated_at->timestamp)->toBe($deactivatedAt->timestamp);
});

test('reactivating clears deactivated_at', function (): void {
    $user = User::factory()->create(['deactivated_at' => now()]);

    activation()->reactivate($user);

    expect($user->fresh()->isDeactivated())->toBeFalse();
});

test('reactivating an active account is a no-op', function (): void {
    $user = User::factory()->create();

    activation()->reactivate($user);

    expect($user->fresh()->isDeactivated())->toBeFalse();
});
