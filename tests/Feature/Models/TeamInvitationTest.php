<?php

use App\Models\TeamInvitation;

test('a fresh invitation is pending', function (): void {
    $invitation = TeamInvitation::factory()->create();

    expect($invitation->isPending())->toBeTrue()
        ->and($invitation->isAccepted())->toBeFalse()
        ->and($invitation->isExpired())->toBeFalse();
});

test('an accepted invitation is not pending', function (): void {
    $invitation = TeamInvitation::factory()->accepted()->create();

    expect($invitation->isPending())->toBeFalse()
        ->and($invitation->isAccepted())->toBeTrue();
});

test('an expired invitation is not pending', function (): void {
    $invitation = TeamInvitation::factory()->expired()->create();

    expect($invitation->isPending())->toBeFalse()
        ->and($invitation->isExpired())->toBeTrue();
});

test('a generated code is assigned on creation', function (): void {
    $invitation = TeamInvitation::factory()->create();

    expect($invitation->code)->toHaveLength(64);
});
