<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Str;

test('a channel creator can archive their channel and is redirected to #general', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);
    $channel->members()->attach($owner->id);

    $this->actingAs($owner)
        ->post(route('channels.archive', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertRedirect(route('channels.index', ['team' => $team->slug]));

    expect($channel->fresh()->isArchived())->toBeTrue();
});

test('a team admin can archive a channel they did not create', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);

    $this->actingAs($admin)
        ->post(route('channels.archive', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertRedirect();

    expect($channel->fresh()->isArchived())->toBeTrue();
});

test('a plain member who did not create a channel cannot archive it', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);

    $this->actingAs($member)
        ->post(route('channels.archive', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertForbidden();

    expect($channel->fresh()->isArchived())->toBeFalse();
});

test('the #general channel cannot be archived', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = $team->channels()->where('slug', Channel::GENERAL_SLUG)->firstOrFail();

    $this->actingAs($owner)
        ->post(route('channels.archive', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertForbidden();

    expect($general->fresh()->isArchived())->toBeFalse();
});

test('an already-archived channel cannot be archived again', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->archived()->create(['created_by' => $owner->id]);

    $this->actingAs($owner)
        ->post(route('channels.archive', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertForbidden();
});

test('archiving keeps the channel and its messages, only setting archived_at', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);
    Message::factory()->for($channel)->for($owner)->create(['body' => 'Still here after archiving.']);

    $this->actingAs($owner)
        ->post(route('channels.archive', ['team' => $team->slug, 'channel' => $channel->slug]));

    expect(Channel::whereKey($channel->id)->exists())->toBeTrue()
        ->and($channel->fresh()->messages()->count())->toBe(1);
});

test('an archived channel is hidden from the active sidebar channel list', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $active = Channel::factory()->for($team)->create(['name' => 'Announcements', 'slug' => 'announcements', 'created_by' => $owner->id]);
    $active->members()->attach($owner->id);
    $archived = Channel::factory()->for($team)->archived()->create(['created_by' => $owner->id]);
    $archived->members()->attach($owner->id);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(fn ($page) => $page
            ->has('channels', 2)
            ->where('channels.0.slug', 'announcements')
            ->where('channels.1.slug', Channel::GENERAL_SLUG));
});

test('the archive control is offered to a user who may archive the channel', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->create(['created_by' => $owner->id]);
    $channel->members()->attach($owner->id);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $channel->slug]))
        ->assertInertia(fn ($page) => $page->where('canArchive', true));
});

test('the archive control is withheld from a user who may not archive the channel', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = $team->channels()->where('slug', Channel::GENERAL_SLUG)->firstOrFail();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn ($page) => $page->where('canArchive', false));
});

test('a user who cannot post to an archived channel is forbidden', function (): void {
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $channel = Channel::factory()->for($team)->archived()->create(['created_by' => $owner->id]);
    $channel->members()->attach($owner->id);

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $channel->slug]), [
            'body' => 'This should be rejected.',
            'client_uuid' => (string) Str::uuid(),
        ])
        ->assertForbidden();
});
