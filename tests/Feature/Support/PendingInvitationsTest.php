<?php

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\PendingInvitations;

/**
 * The team slugs the read-model reports, in the order it reports them.
 *
 * @param  array<int, array{code: string, inviterName: string, team: array{name: string, slug: string}}>  $invitations
 * @return array<int, string>
 */
function invitedTeamSlugs(array $invitations): array
{
    return array_map(fn (array $invitation): string => $invitation['team']['slug'], $invitations);
}

test('an open invitation is matched on the email regardless of its case', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $inviter = User::factory()->create(['name' => 'Grace']);
    $team = Team::factory()->create(['name' => 'Acme']);

    $invitation = TeamInvitation::factory()->for($team)->create([
        'email' => 'ADA@Example.com',
        'invited_by' => $inviter->id,
    ]);

    expect(PendingInvitations::forUser($user))->toBe([[
        'code' => $invitation->code,
        'inviterName' => 'Grace',
        'team' => ['name' => 'Acme', 'slug' => $team->slug],
    ]]);
});

test('accepted and expired invitations are left out, an open-ended one is kept', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $open = Team::factory()->create();
    TeamInvitation::factory()->for($open)->create(['email' => $user->email, 'expires_at' => null]);

    $live = Team::factory()->create();
    TeamInvitation::factory()->for($live)->expiresIn(3)->create(['email' => $user->email]);

    TeamInvitation::factory()->for(Team::factory()->create())->accepted()->create(['email' => $user->email]);
    TeamInvitation::factory()->for(Team::factory()->create())->expired()->create(['email' => $user->email]);

    // Somebody else's invitation.
    TeamInvitation::factory()->for(Team::factory()->create())->create(['email' => 'grace@example.com']);

    expect(invitedTeamSlugs(PendingInvitations::forUser($user)))
        ->toHaveCount(2)
        ->toContain($open->slug, $live->slug);
});

test('a user with nothing waiting gets an empty list', function (): void {
    expect(PendingInvitations::forUser(User::factory()->create()))->toBe([]);
});
