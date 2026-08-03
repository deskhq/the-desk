<?php

use App\Enums\TeamRole;
use App\Events\UserTyping;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

test('a member typing signal broadcasts UserTyping with the authenticated identity', function (): void {
    Event::fake([UserTyping::class]);

    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
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

    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
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

    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
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

    ['team' => $team, 'channel' => $general] = teamWithChannel();

    $this->post(route('channels.typing', [
        'team' => $team->slug,
        'channel' => $general->slug,
    ]))->assertRedirect(route('login'));

    Event::assertNotDispatched(UserTyping::class);
});
