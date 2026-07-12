<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a team with its owner already a member of #general.
 *
 * @return array{0: User, 1: Team, 2: Channel}
 */
function teamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

test('a channel member can post a message', function (): void {
    [$owner, $team, $general] = teamWithGeneral();
    $clientUuid = (string) Str::uuid7();

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'Hello team',
            'client_uuid' => $clientUuid,
        ])
        ->assertRedirect(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]));

    $this->assertDatabaseHas('messages', [
        'channel_id' => $general->id,
        'user_id' => $owner->id,
        'client_uuid' => $clientUuid,
        'body' => 'Hello team',
    ]);
});

test('the message body is trimmed and required', function (): void {
    [$owner, $team, $general] = teamWithGeneral();

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => '   ',
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertInvalid(['body']);

    expect(Message::count())->toBe(0);
});

test('a team member who is not a channel member cannot post', function (): void {
    [$owner, $team] = teamWithGeneral();
    $stranger = User::factory()->create();
    $team->memberships()->create(['user_id' => $stranger->id, 'role' => TeamRole::Member]);

    $private = Channel::factory()->for($team)->private()->create();
    $private->channelMembers()->create(['user_id' => $owner->id]);

    $this->actingAs($stranger)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $private->slug]), [
            'body' => 'let me in',
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertForbidden();

    expect(Message::count())->toBe(0);
});

test('nobody can post to an archived channel', function (): void {
    [$owner, $team] = teamWithGeneral();
    $archived = Channel::factory()->for($team)->archived()->create();
    $archived->channelMembers()->create(['user_id' => $owner->id]);

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $archived->slug]), [
            'body' => 'still here?',
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertForbidden();
});

test('resending a message with the same client uuid is idempotent', function (): void {
    [$owner, $team, $general] = teamWithGeneral();
    $clientUuid = (string) Str::uuid7();

    $payload = [
        'team' => $team->slug,
        'channel' => $general->slug,
    ];

    $this->actingAs($owner)->post(route('channels.messages.store', $payload), [
        'body' => 'first and only',
        'client_uuid' => $clientUuid,
    ]);

    $this->actingAs($owner)->post(route('channels.messages.store', $payload), [
        'body' => 'first and only',
        'client_uuid' => $clientUuid,
    ]);

    expect(Message::where('channel_id', $general->id)->count())->toBe(1);
});

test('the channel page returns the newest 50 messages, newest first', function (): void {
    [$owner, $team, $general] = teamWithGeneral();

    // 60 messages, created oldest-to-newest so ordered ids match creation order.
    collect(range(1, 60))->each(fn (int $i) => Message::factory()->for($general)->for($owner)->create([
        'body' => "message {$i}",
    ]));

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('channels/Show')
            ->has('messages.data', 50)
            ->where('messages.data.0.body', 'message 60')
            ->where('messages.data.49.body', 'message 11')
            ->where('messages.data.0.user.name', $owner->name)
            ->whereNot('messages.next_cursor', null)
        );
});

test('older messages load through the cursor', function (): void {
    [$owner, $team, $general] = teamWithGeneral();

    collect(range(1, 60))->each(fn (int $i) => Message::factory()->for($general)->for($owner)->create([
        'body' => "message {$i}",
    ]));

    $showUrl = route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]);

    $cursor = null;
    $this->actingAs($owner)->get($showUrl)
        ->assertInertia(function (Assert $page) use (&$cursor): void {
            $cursor = $page->toArray()['props']['messages']['next_cursor'];
        });

    $this->actingAs($owner)
        ->get($showUrl.'?'.http_build_query(['cursor' => $cursor]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('messages.data', 10)
            ->where('messages.data.0.body', 'message 10')
            ->where('messages.data.9.body', 'message 1')
            ->where('messages.next_cursor', null)
        );
});

test('an empty channel returns no messages', function (): void {
    [$owner, $team, $general] = teamWithGeneral();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('messages.data', 0)
            ->where('messages.next_cursor', null)
        );
});
