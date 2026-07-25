<?php

declare(strict_types=1);

use App\Data\TeamInvitationContextData;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;

/**
 * The host domain the invitation panel names is derived from whatever the
 * operator put in APP_URL, which is not always a tidy absolute URL.
 */
function invitationContext(): TeamInvitationContextData
{
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    return TeamInvitationContextData::fromInvitation(
        TeamInvitation::factory()->create([
            'team_id' => $team->id,
            'invited_by' => $owner->id,
        ]),
    );
}

test('the host domain is the host of the configured app url', function (): void {
    config(['app.url' => 'https://desk.acme.co/some/path']);

    expect(invitationContext()->hostDomain)->toBe('desk.acme.co');
});

test('an app url with no host falls back to the configured value verbatim', function (): void {
    config(['app.url' => 'desk.acme.co']);

    expect(invitationContext()->hostDomain)->toBe('desk.acme.co');
});

test('an unset app url yields no host domain', function (): void {
    config(['app.url' => null]);

    expect(invitationContext()->hostDomain)->toBe('');
});
