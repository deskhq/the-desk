<?php

use App\Models\TeamInvitation;
use App\Models\User;
use App\Rules\ValidTeamInvitation;

/**
 * Run the rule and capture the first failure message, or null when it passes.
 */
function runInvitationRule(?User $user, mixed $value): ?string
{
    $message = null;

    (new ValidTeamInvitation($user))->validate('invitation', $value, function (string $failure) use (&$message) {
        $message ??= $failure;
    });

    return $message;
}

test('a missing invitation fails', function () {
    $user = User::factory()->create();

    expect(runInvitationRule($user, null))
        ->toBe('This invitation was sent to a different email address.');
});

test('a missing user fails', function () {
    $invitation = TeamInvitation::factory()->create();

    expect(runInvitationRule(null, $invitation))
        ->toBe('This invitation was sent to a different email address.');
});

test('an accepted invitation fails', function () {
    $user = User::factory()->create();
    $invitation = TeamInvitation::factory()->accepted()->create(['email' => $user->email]);

    expect(runInvitationRule($user, $invitation))
        ->toBe('This invitation has already been accepted.');
});

test('an expired invitation fails', function () {
    $user = User::factory()->create();
    $invitation = TeamInvitation::factory()->expired()->create(['email' => $user->email]);

    expect(runInvitationRule($user, $invitation))
        ->toBe('This invitation has expired.');
});

test('an invitation for another email fails', function () {
    $user = User::factory()->create();
    $invitation = TeamInvitation::factory()->create(['email' => 'someone-else@example.com']);

    expect(runInvitationRule($user, $invitation))
        ->toBe('This invitation was sent to a different email address.');
});

test('a valid invitation passes', function () {
    $user = User::factory()->create();
    $invitation = TeamInvitation::factory()->create(['email' => $user->email]);

    expect(runInvitationRule($user, $invitation))->toBeNull();
});
