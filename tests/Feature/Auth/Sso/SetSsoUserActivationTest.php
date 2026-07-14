<?php

use App\Actions\Sso\SetSsoUserActivation;
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
