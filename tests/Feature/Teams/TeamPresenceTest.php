<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Exercise the presence channel authorization against a real broadcaster.
 *
 * The test suite defaults to the `null` broadcaster, which authorizes every
 * subscription without consulting routes/channels.php. Switching to a real
 * driver and reloading the channel definitions onto it lets the authorization
 * callback actually run.
 */
function usePresenceBroadcaster(): void
{
    config(['broadcasting.default' => 'reverb']);

    require base_path('routes/channels.php');
}

test('a team member is authorized to join the team presence channel and receives their roster identity', function () {
    usePresenceBroadcaster();

    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $response = $this->actingAs($member)
        ->post('/broadcasting/auth', [
            'channel_name' => 'presence-team.'.$team->id,
            'socket_id' => '1234.5678',
        ])
        ->assertOk();

    $channelData = json_decode($response->json('channel_data'), true);

    expect($channelData['user_info'])->toBe(['id' => $member->id, 'name' => $member->name]);
});

test('a non-member cannot join the team presence channel', function () {
    usePresenceBroadcaster();

    $team = Team::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post('/broadcasting/auth', [
            'channel_name' => 'presence-team.'.$team->id,
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});

test('joining the presence channel of an unknown team is denied', function () {
    usePresenceBroadcaster();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => 'presence-team.'.Str::uuid7(),
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});
