<?php

use App\Actions\Channels\DeleteChannel;
use App\Actions\Teams\CreateTeam;
use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditActivity;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * An admin, their workspace, and a channel they have just deleted.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function recentlyDeletedFixture(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->create(['name' => 'Roadmap', 'slug' => 'roadmap']);
    $channel->channelMembers()->create(['user_id' => $owner->id]);

    app(DeleteChannel::class)->handle($channel, null);

    return [$owner, $team, $channel];
}

test('the recently-deleted panel lists a workspace’s deleted channels with their purge date', function (): void {
    [$owner, $team, $channel] = recentlyDeletedFixture();

    $this->actingAs($owner)
        ->get(route('teams.deleted-channels.index', ['team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/DeletedChannels')
            ->has('channels', 1)
            ->where('channels.0.name', 'Roadmap')
            ->where('channels.0.id', $channel->id)
            ->has('channels.0.deletedAt')
            ->has('channels.0.purgeAt'));
});

test('the panel counts what each deleted channel still holds', function (): void {
    [$owner, $team, $channel] = recentlyDeletedFixture();
    Message::factory()->count(3)->for($channel)->for($owner)->create();

    $this->actingAs($owner)
        ->get(route('teams.deleted-channels.index', ['team' => $team->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('channels.0.summary.messageCount', 3)
            ->where('channels.0.summary.memberCount', 1)
            ->etc());
});

test('the panel never lists live channels', function (): void {
    [$owner, $team] = recentlyDeletedFixture();

    $this->actingAs($owner)
        ->get(route('teams.deleted-channels.index', ['team' => $team->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page->where(
            'channels',
            fn (Collection $channels): bool => $channels->doesntContain('name', 'general'),
        ));
});

test('a plain member cannot open the recently-deleted panel', function (): void {
    [, $team] = recentlyDeletedFixture();
    $member = User::factory()->create();
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $this->actingAs($member)
        ->get(route('teams.deleted-channels.index', ['team' => $team->slug]))
        ->assertForbidden();
});

test('an admin restores a deleted channel, bringing it and its messages back', function (): void {
    [$owner, $team, $channel] = recentlyDeletedFixture();
    Message::factory()->for($channel)->for($owner)->create(['body' => 'still here']);

    $this->actingAs($owner)
        ->post(route('teams.deleted-channels.restore', ['team' => $team->slug, 'channel' => $channel->id]))
        ->assertRedirect(route('teams.deleted-channels.index', ['team' => $team->slug]));

    expect(Channel::query()->whereKey($channel->id)->exists())->toBeTrue()
        ->and($owner->visibleChannelIds($team)->all())->toContain($channel->id)
        ->and(Message::where('channel_id', $channel->id)->where('body', 'still here')->exists())->toBeTrue();
});

test('restoring a channel records it in the workspace audit log', function (): void {
    [$owner, $team, $channel] = recentlyDeletedFixture();

    $this->actingAs($owner)
        ->post(route('teams.deleted-channels.restore', ['team' => $team->slug, 'channel' => $channel->id]))
        ->assertRedirect();

    $entry = AuditActivity::where('team_id', $team->id)
        ->where('event', AuditAction::ChannelRestored->value)
        ->sole();

    expect($entry->properties->get('channel_name'))->toBe('Roadmap');
});

test('a plain member cannot restore a deleted channel', function (): void {
    [, $team, $channel] = recentlyDeletedFixture();
    $member = User::factory()->create();
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $this->actingAs($member)
        ->post(route('teams.deleted-channels.restore', ['team' => $team->slug, 'channel' => $channel->id]))
        ->assertForbidden();

    expect(Channel::query()->whereKey($channel->id)->exists())->toBeFalse();
});

test('a channel cannot be restored once a new one has taken its name', function (): void {
    [$owner, $team, $channel] = recentlyDeletedFixture();
    Channel::factory()->for($team)->create(['name' => 'Roadmap', 'slug' => 'roadmap']);

    $this->actingAs($owner)
        ->from(route('teams.deleted-channels.index', ['team' => $team->slug]))
        ->post(route('teams.deleted-channels.restore', ['team' => $team->slug, 'channel' => $channel->id]))
        ->assertSessionHasErrors('channel');

    expect(Channel::withTrashed()->whereKey($channel->id)->sole()->trashed())->toBeTrue();
});

test('a channel from another workspace is not restorable through this one', function (): void {
    [$owner, $team] = recentlyDeletedFixture();
    $stranger = User::factory()->create();
    $otherTeam = app(CreateTeam::class)->handle($stranger, 'Globex');
    $otherChannel = Channel::factory()->for($otherTeam)->create();
    app(DeleteChannel::class)->handle($otherChannel, null);

    $this->actingAs($owner)
        ->post(route('teams.deleted-channels.restore', ['team' => $team->slug, 'channel' => $otherChannel->id]))
        ->assertNotFound();
});
