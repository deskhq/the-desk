<?php

use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The archive endpoint (#1117)
|--------------------------------------------------------------------------
|
| That an archived channel drops out of the sidebar list is a claim about
| `SidebarChannels`, proven against it in
| `tests/Integration/Support/SidebarChannelsTest.php`. What is left here is the
| HTTP contract: who may archive, where they land, and what the endpoint
| refuses.
|
*/

test('a channel creator can archive their channel and is redirected to #general', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);
    $channel->members()->attach($owner->id);

    $this->actingAs($owner)
        ->post(route('channels.archive', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertRedirect(route('channels.index', ['team' => $team->slug]));

    expect($channel->fresh()->isArchived())->toBeTrue();
});

test('a team admin can archive a channel they did not create', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $admin = teamMemberInChannel($general, role: TeamRole::Admin);
    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);

    $this->actingAs($admin)
        ->post(route('channels.archive', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertRedirect();

    expect($channel->fresh()->isArchived())->toBeTrue();
});

test('a plain member who did not create a channel cannot archive it', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);

    $this->actingAs($member)
        ->post(route('channels.archive', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertForbidden();

    expect($channel->fresh()->isArchived())->toBeFalse();
});

test('the #general channel cannot be archived', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $this->actingAs($owner)
        ->post(route('channels.archive', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertForbidden();

    expect($general->fresh()->isArchived())->toBeFalse();
});

test('an already-archived channel cannot be archived again', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $channel = Channel::factory()->for($team)->archived()->create(['created_by' => $owner->id]);

    $this->actingAs($owner)
        ->post(route('channels.archive', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertForbidden();
});

test('archiving keeps the channel and its messages, only setting archived_at', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);
    Message::factory()->for($channel)->for($owner)->create(['body' => 'Still here after archiving.']);

    $this->actingAs($owner)
        ->post(route('channels.archive', ['team' => $team->slug, 'channel' => $channel->slug]));

    expect(Channel::whereKey($channel->id)->exists())->toBeTrue()
        ->and($channel->fresh()->messages()->count())->toBe(1);
});

test('the archive control is offered to a user who may archive the channel', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);
    $channel->members()->attach($owner->id);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertInertia(fn ($page) => $page->where('canArchive', true));
});

test('the archive control is withheld from a user who may not archive the channel', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn ($page) => $page->where('canArchive', false));
});

test('a user who cannot post to an archived channel is forbidden', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();
    $channel = Channel::factory()->for($team)->archived()->create(['created_by' => $owner->id]);
    $channel->members()->attach($owner->id);

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'body' => 'This should be rejected.',
            'client_uuid' => (string) Str::uuid(),
        ])
        ->assertForbidden();
});
