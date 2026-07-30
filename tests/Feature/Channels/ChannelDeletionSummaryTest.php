<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * An admin and a channel of theirs holding two messages, one file, and two
 * members.
 *
 * @return array{0: User, 1: Team, 2: Channel, 3: User}
 */
function summaryFixture(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $channel = Channel::factory()->for($team)->create(['name' => 'Roadmap', 'slug' => 'roadmap']);
    $channel->channelMembers()->create(['user_id' => $owner->id]);
    $channel->channelMembers()->create(['user_id' => $member->id]);

    $message = Message::factory()->for($channel)->for($owner)->create();
    Message::factory()->for($channel)->for($owner)->create();
    Attachment::factory()->for($channel)->for($owner)->create(['message_id' => $message->id]);

    return [$owner, $team, $channel, $member];
}

test('the deletion summary reports what the channel would cost to delete', function (): void {
    [$owner, $team, $channel] = summaryFixture();

    $this->actingAs($owner)
        ->getJson(route('channels.deletion-summary', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertOk()
        ->assertExactJson([
            'messageCount' => 2,
            'fileCount' => 1,
            'memberCount' => 2,
        ]);
});

test('the deletion summary excludes messages already deleted individually', function (): void {
    [$owner, $team, $channel] = summaryFixture();
    $channel->messages()->first()->delete();

    $this->actingAs($owner)
        ->getJson(route('channels.deletion-summary', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertJsonPath('messageCount', 1);
});

test('a member who cannot delete the channel cannot read its deletion summary', function (): void {
    [, $team, $channel, $member] = summaryFixture();

    $this->actingAs($member)
        ->getJson(route('channels.deletion-summary', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertForbidden();
});

test('the channel view tells the client whether the viewer may delete the channel', function (): void {
    [$owner, $team, $channel, $member] = summaryFixture();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page->where('canDelete', true)->etc());

    $this->actingAs($member)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page->where('canDelete', false)->etc());
});
