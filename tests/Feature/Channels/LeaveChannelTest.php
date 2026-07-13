<?php

use App\Actions\Channels\LeaveChannel;
use App\Actions\Teams\CreateTeam;
use App\Enums\MessageType;
use App\Enums\TeamRole;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * Create a team owner plus a standard channel they are a member of.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function teamWithJoinedChannel(): array
{
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $channel = Channel::factory()->for($team)->create(['name' => 'Marketing', 'slug' => 'marketing']);
    $channel->channelMembers()->create(['user_id' => $user->id]);

    return [$user, $team, $channel];
}

test('a member can leave a public standard channel and is redirected to #general', function (): void {
    [$user, $team, $channel] = teamWithJoinedChannel();

    $this->actingAs($user)
        ->post(route('channels.leave', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]));

    expect($channel->members()->whereKey($user->id)->exists())->toBeFalse();
});

test('a member can leave a private standard channel', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);
    $channel->channelMembers()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('channels.leave', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]));

    expect($channel->members()->whereKey($user->id)->exists())->toBeFalse();
});

test('the last member may leave a private channel, orphaning it', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);
    $channel->channelMembers()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('channels.leave', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertRedirect();

    expect($channel->channelMembers()->count())->toBe(0);
});

test('leaving posts a member_left system notice authored by the leaver', function (): void {
    [$user, $team, $channel] = teamWithJoinedChannel();

    $this->actingAs($user)
        ->post(route('channels.leave', ['team' => $team->slug, 'channel' => $channel->slug]));

    $notice = $channel->messages()->firstOrFail();

    expect($notice->type)->toBe(MessageType::MemberLeft)
        ->and($notice->user_id)->toBe($user->id)
        ->and($notice->body)->toBe('');
});

test('the leave notice broadcasts MessageSent so it appears live', function (): void {
    Event::fake([MessageSent::class]);

    [$user, $team, $channel] = teamWithJoinedChannel();

    $this->actingAs($user)
        ->post(route('channels.leave', ['team' => $team->slug, 'channel' => $channel->slug]));

    Event::assertDispatched(MessageSent::class, fn (MessageSent $event): bool => $event->channel->is($channel)
        && $event->message->type === MessageType::MemberLeft);
});

test('the #general channel cannot be left', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    $this->actingAs($user)
        ->post(route('channels.leave', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertForbidden();

    expect($general->members()->whereKey($user->id)->exists())->toBeTrue();
});

test('a direct message cannot be left', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $dm = Channel::factory()->for($team)->direct()->create();
    $dm->channelMembers()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('channels.leave', ['team' => $team->slug, 'channel' => $dm->slug]))
        ->assertForbidden();
});

test('a non-member cannot leave a channel', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $channel = Channel::factory()->for($team)->create(['slug' => 'marketing']);

    $this->actingAs($user)
        ->post(route('channels.leave', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertForbidden();
});

test('the leave policy denies #general, direct messages, and non-members', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();
    $standard = Channel::factory()->for($team)->create(['slug' => 'marketing']);
    $dm = Channel::factory()->for($team)->direct()->create();
    $dm->channelMembers()->create(['user_id' => $user->id]);

    expect($user->can('leave', $general))->toBeFalse()
        ->and($user->can('leave', $dm))->toBeFalse()
        ->and($user->can('leave', $standard))->toBeFalse();

    $standard->channelMembers()->create(['user_id' => $user->id]);

    expect($user->fresh()->can('leave', $standard))->toBeTrue();
});

test('the LeaveChannel action removes the membership and records the notice', function (): void {
    [$user, , $channel] = teamWithJoinedChannel();

    app(LeaveChannel::class)->handle($channel, $user);

    expect($channel->members()->whereKey($user->id)->exists())->toBeFalse()
        ->and($channel->messages()->where('type', MessageType::MemberLeft)->where('user_id', $user->id)->exists())->toBeTrue();
});

test('an admin removing another member is unaffected by the self-leave route', function (): void {
    // The self-leave route only ever removes the caller; a member of a team
    // still cannot leave a channel they do not belong to.
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);
    $channel = Channel::factory()->for($team)->private()->create(['slug' => 'secret']);
    $channel->channelMembers()->create(['user_id' => $owner->id]);

    $this->actingAs($stranger)
        ->post(route('channels.leave', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertForbidden();

    expect($channel->members()->whereKey($owner->id)->exists())->toBeTrue();
});
