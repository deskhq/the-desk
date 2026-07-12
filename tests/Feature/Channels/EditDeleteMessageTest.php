<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function editTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

test('the author can edit their own message and edited_at is set', function (): void {
    [$owner, $team, $general] = editTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create(['body' => 'original']);

    $this->actingAs($owner)
        ->patch(route('channels.messages.update', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'message' => $message->id,
        ]), ['body' => 'edited body'])
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]));

    $message->refresh();

    expect($message->body)->toBe('edited body')
        ->and($message->edited_at)->not->toBeNull();
});

test('the message body is trimmed and required on edit', function (): void {
    [$owner, $team, $general] = editTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create(['body' => 'original']);

    $this->actingAs($owner)
        ->patch(route('channels.messages.update', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'message' => $message->id,
        ]), ['body' => '   '])
        ->assertInvalid(['body']);

    expect($message->refresh()->body)->toBe('original');
});

test('a user cannot edit another members message', function (): void {
    [$owner, $team, $general] = editTeamWithGeneral();
    $other = User::factory()->create();
    $team->memberships()->create(['user_id' => $other->id, 'role' => TeamRole::Member]);

    $message = Message::factory()->for($general)->for($owner)->create(['body' => 'original']);

    $this->actingAs($other)
        ->patch(route('channels.messages.update', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'message' => $message->id,
        ]), ['body' => 'hijacked'])
        ->assertForbidden();

    expect($message->refresh()->body)->toBe('original');
});

test('the author can delete their own message', function (): void {
    [$owner, $team, $general] = editTeamWithGeneral();
    $message = Message::factory()->for($general)->for($owner)->create();

    $this->actingAs($owner)
        ->delete(route('channels.messages.destroy', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'message' => $message->id,
        ]))
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]));

    expect($message->fresh()->deleted_at)->not->toBeNull();
});

test('a team admin can delete another members message', function (): void {
    [$owner, $team, $general] = editTeamWithGeneral();
    $admin = User::factory()->create();
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);

    $message = Message::factory()->for($general)->for($owner)->create();

    $this->actingAs($admin)
        ->delete(route('channels.messages.destroy', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'message' => $message->id,
        ]))
        ->assertRedirect();

    expect($message->fresh()->deleted_at)->not->toBeNull();
});

test('a plain member cannot delete another members message', function (): void {
    [$owner, $team, $general] = editTeamWithGeneral();
    $member = User::factory()->create();
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $message = Message::factory()->for($general)->for($owner)->create();

    $this->actingAs($member)
        ->delete(route('channels.messages.destroy', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'message' => $message->id,
        ]))
        ->assertForbidden();

    expect($message->fresh()->deleted_at)->toBeNull();
});

test('the channel page includes deleted messages as tombstones with a blanked body', function (): void {
    [$owner, $team, $general] = editTeamWithGeneral();
    Message::factory()->for($general)->for($owner)->create(['body' => 'kept']);
    $deleted = Message::factory()->for($general)->for($owner)->create(['body' => 'secret']);
    $deleted->delete();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('messages.data', 2)
            ->where('messages.data.0.isDeleted', true)
            ->where('messages.data.0.body', '')
            ->where('messages.data.1.isDeleted', false)
            ->where('messages.data.1.body', 'kept')
        );
});
