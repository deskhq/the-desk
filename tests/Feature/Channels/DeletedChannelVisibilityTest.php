<?php

use App\Actions\Channels\DeleteChannel;
use App\Actions\Channels\OpenDirectMessage;
use App\Actions\Channels\SearchMessages;
use App\Actions\Teams\CreateTeam;
use App\Data\MessageSearchCriteria;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * An admin, their workspace, and a channel they belong to that is about to be
 * deleted.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function deletedChannelFixture(ChannelVisibility $visibility = ChannelVisibility::Public): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->create([
        'name' => 'Roadmap',
        'slug' => 'roadmap',
        'visibility' => $visibility,
    ]);
    $channel->channelMembers()->create(['user_id' => $owner->id]);

    return [$owner, $team, $channel];
}

test('a deleted channel leaves the sidebar at once', function (): void {
    [$owner, $team, $channel] = deletedChannelFixture();

    app(DeleteChannel::class)->handle($channel);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(fn (Assert $page): Assert => $page->where(
            'channels',
            fn (Collection $channels): bool => $channels->doesntContain('slug', 'roadmap'),
        ));
});

test('a deleted channel is no longer reachable by URL', function (): void {
    [$owner, $team, $channel] = deletedChannelFixture();

    app(DeleteChannel::class)->handle($channel);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => 'roadmap']))
        ->assertNotFound();
});

test('a deleted public channel drops out of the browse list', function (): void {
    [$owner, $team, $channel] = deletedChannelFixture();
    $channel->channelMembers()->delete();

    app(DeleteChannel::class)->handle($channel);

    $this->actingAs($owner)
        ->get(route('channels.browse', ['team' => $team->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page->where(
            'joinableChannels',
            fn (Collection $channels): bool => $channels->doesntContain('slug', 'roadmap'),
        ));
});

test('a deleted channel drops out of the visible-channel ACL', function (): void {
    [$owner, $team, $channel] = deletedChannelFixture();

    app(DeleteChannel::class)->handle($channel);

    expect($owner->visibleChannelIds($team)->all())->not->toContain($channel->id)
        ->and($owner->visibleChannelIdsAcrossTeams()->all())->not->toContain($channel->id);
});

test('messages in a deleted channel stop matching search', function (): void {
    [$owner, $team, $channel] = deletedChannelFixture();
    Message::factory()->for($channel)->for($owner)->create(['body' => 'quarterly roadmap planning']);

    $criteria = new MessageSearchCriteria(query: 'quarterly');

    expect(app(SearchMessages::class)->handle($owner, $team, $criteria))->toHaveCount(1);

    app(DeleteChannel::class)->handle($channel);

    expect(app(SearchMessages::class)->handle($owner, $team, $criteria))->toHaveCount(0);
});

test('a direct message can never be deleted, however senior the admin', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $other->id, 'role' => TeamRole::Member]);
    $dm = app(OpenDirectMessage::class)->handle($team, $owner, $other);

    $this->actingAs($owner)
        ->delete(route('channels.destroy', ['team' => $team->slug, 'channel' => $dm->slug]), ['name' => 'anything'])
        ->assertForbidden();

    expect(Channel::query()->whereKey($dm->id)->exists())->toBeTrue();
});

test('deleting a channel releases its name, so the same one can be created again', function (): void {
    [$owner, $team, $channel] = deletedChannelFixture();

    app(DeleteChannel::class)->handle($channel);

    $this->actingAs($owner)
        ->post(route('channels.store', ['team' => $team->slug]), [
            'name' => 'Roadmap',
            'visibility' => ChannelVisibility::Public->value,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => 'roadmap']));

    expect(Channel::query()->where('team_id', $team->id)->where('slug', 'roadmap')->count())->toBe(1);
});
