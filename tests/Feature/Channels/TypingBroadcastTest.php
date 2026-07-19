<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Events\UserTyping;
use App\Models\Channel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function typingTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

test('a member typing signal broadcasts UserTyping with the authenticated identity', function (): void {
    Event::fake([UserTyping::class]);

    [$owner, $team, $general] = typingTeamWithGeneral();
    $owner->update(['name' => 'Ada Lovelace']);

    $this->actingAs($owner)->post(route('channels.typing', [
        'team' => $team->slug,
        'channel' => $general->slug,
    ]))->assertNoContent();

    Event::assertDispatched(UserTyping::class, function (UserTyping $event) use ($general, $owner): bool {
        $target = $event->broadcastOn()[0];

        expect($target)->toBeInstanceOf(PrivateChannel::class)
            ->and($target->name)->toBe('private-channel.'.$general->id);

        return $event->broadcastWith() === ['id' => $owner->id, 'name' => 'Ada Lovelace'];
    });
});

test('a team member who is not in the channel cannot broadcast typing', function (): void {
    Event::fake([UserTyping::class]);

    [$owner, $team, $general] = typingTeamWithGeneral();
    $outsider = User::factory()->create();
    $team->memberships()->create(['user_id' => $outsider->id, 'role' => TeamRole::Member]);
    $private = Channel::factory()->for($team)->private()->create();
    $private->channelMembers()->firstOrCreate(['user_id' => $owner->id]);

    $this->actingAs($outsider)->post(route('channels.typing', [
        'team' => $team->slug,
        'channel' => $private->slug,
    ]))->assertForbidden();

    Event::assertNotDispatched(UserTyping::class);
});

test('typing is rejected on an archived channel', function (): void {
    Event::fake([UserTyping::class]);

    [$owner, $team, $general] = typingTeamWithGeneral();
    $archived = Channel::factory()->for($team)->archived()->create();
    $archived->channelMembers()->firstOrCreate(['user_id' => $owner->id]);

    $this->actingAs($owner)->post(route('channels.typing', [
        'team' => $team->slug,
        'channel' => $archived->slug,
    ]))->assertForbidden();

    Event::assertNotDispatched(UserTyping::class);
});

test('a guest is redirected to login instead of broadcasting typing', function (): void {
    Event::fake([UserTyping::class]);

    [, $team, $general] = typingTeamWithGeneral();

    $this->post(route('channels.typing', [
        'team' => $team->slug,
        'channel' => $general->slug,
    ]))->assertRedirect(route('login'));

    Event::assertNotDispatched(UserTyping::class);
});
