<?php

use App\Actions\Channels\CreateChannel;
use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelVisibility;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('creating a team auto-creates a #general channel', function (): void {
    $user = User::factory()->create();

    $team = app(CreateTeam::class)->handle($user, 'Acme');

    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->first();

    expect($general)->not->toBeNull()
        ->and($general->name)->toBe('general')
        ->and($general->visibility->value)->toBe('public')
        ->and($general->created_by)->toBe($user->id);
});

test('the team owner is auto-joined to #general on team creation', function (): void {
    $user = User::factory()->create();

    $team = app(CreateTeam::class)->handle($user, 'Acme');

    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    expect($general->members()->whereKey($user->id)->exists())->toBeTrue();
});

test('a new team member is auto-joined to #general when accepting an invitation', function (): void {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser)->get(route('invitations.accept', $invitation));

    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    expect($general->members()->whereKey($invitedUser->id)->exists())->toBeTrue();
});

test('the channel page lists the current user\'s channels in the sidebar', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    $this->actingAs($user)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('channels/Show')
            ->has('channels', 1)
            ->where('channels.0.slug', 'general')
            ->where('channels.0.name', 'general')
            ->where('channels.0.isGeneral', true)
        );
});

test('the CreateChannel action creates a channel, strips a leading # and joins the creator', function (): void {
    $creator = User::factory()->create();
    $team = app(CreateTeam::class)->handle($creator, 'Acme');

    $channel = app(CreateChannel::class)->handle($team, '#Marketing', ChannelVisibility::Private, $creator);

    expect($channel->name)->toBe('Marketing')
        ->and($channel->slug)->toBe('marketing')
        ->and($channel->visibility)->toBe(ChannelVisibility::Private)
        ->and($channel->team_id)->toBe($team->id)
        ->and($channel->members()->whereKey($creator->id)->exists())->toBeTrue();
});

test('a bare team URL redirects to the #general channel', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');

    $this->actingAs($user)
        ->get(route('channels.index', ['team' => $team->slug]))
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => 'general']));
});

test('a user who is not a team member cannot view the team\'s channels', function (): void {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($outsider)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => 'general']))
        ->assertForbidden();
});

test('the channel page exposes the viewer\'s read pointer for the new-messages divider', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    $messages = Message::factory()->count(3)->for($general)->for($user)->create();
    $user->channels()->updateExistingPivot($general->id, ['last_read_message_id' => $messages[1]->id]);

    $this->actingAs($user)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('lastReadMessageId', (string) $messages[1]->id)
        );
});

test('the read pointer is null on a channel the viewer has never read', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    Message::factory()->for($general)->for($user)->create();

    $this->actingAs($user)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('lastReadMessageId', null));
});

test('opening a channel with more unread than a page windows the initial page around the read boundary', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    // Authorship is irrelevant to the id-based window; only the read pointer is.
    $messages = collect(range(1, 100))->map(
        fn (int $i) => Message::factory()->for($general)->for($user)->create(['body' => "message {$i}"])
    );

    // Read through message 30, so messages 31..100 (70 unread) exceed one page.
    $user->channels()->updateExistingPivot($general->id, ['last_read_message_id' => $messages[29]->id]);

    $this->actingAs($user)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            // The window is capped 39 messages past the boundary (message 71) and
            // holds the newest 50 rows at or below that cap — messages 22..71 —
            // so the boundary (message 31) is loaded while the newest history
            // (up to message 100) pages in below via InfiniteScroll.
            ->has('messages.data', 50)
            ->where('messages.data.0.body', 'message 71')
            ->where('messages.data.49.body', 'message 22')
        );
});

test('opening a channel with unread within a page keeps the default newest window', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    $messages = collect(range(1, 60))->map(
        fn (int $i) => Message::factory()->for($general)->for($user)->create(['body' => "message {$i}"])
    );

    // Read through message 20, so messages 21..60 (40 unread) fit within one page.
    $user->channels()->updateExistingPivot($general->id, ['last_read_message_id' => $messages[19]->id]);

    $this->actingAs($user)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            // No window: the boundary already sits in the newest page, so the view
            // opens at newest (messages 11..60) with the boundary (message 21) loaded.
            ->has('messages.data', 50)
            ->where('messages.data.0.body', 'message 60')
            ->where('messages.data.49.body', 'message 11')
        );
});

test('a never-read channel with a long backlog still opens at newest', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    // Auto-joined with a null read pointer: there is no last-read boundary to
    // anchor, so #76 deliberately leaves the default newest window in place
    // rather than dropping the viewer at the oldest message.
    collect(range(1, 60))->each(
        fn (int $i) => Message::factory()->for($general)->for($user)->create(['body' => "message {$i}"])
    );

    $this->actingAs($user)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('messages.data', 50)
            ->where('messages.data.0.body', 'message 60')
            ->where('messages.data.49.body', 'message 11')
        );
});

test('a fully-read channel opens at newest without a window', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    $messages = collect(range(1, 60))->map(
        fn (int $i) => Message::factory()->for($general)->for($user)->create(['body' => "message {$i}"])
    );

    // Read pointer at the newest message: nothing is unread, so no window applies.
    $user->channels()->updateExistingPivot($general->id, ['last_read_message_id' => $messages[59]->id]);

    $this->actingAs($user)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('messages.data', 50)
            ->where('messages.data.0.body', 'message 60')
            ->where('messages.data.49.body', 'message 11')
        );
});
