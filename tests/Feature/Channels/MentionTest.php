<?php

use App\Actions\Teams\CreateTeam;
use App\Data\MessageData;
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
function mentionTeamWithGeneral(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $general = Channel::where('team_id', $team->id)->where('slug', 'general')->firstOrFail();

    return [$owner, $team, $general];
}

/**
 * Add a user to the team as a plain member and return them.
 */
function teamMember(Team $team, ?string $name = null): User
{
    $user = User::factory()->create($name ? ['name' => $name] : []);
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    return $user;
}

/**
 * Build a mention token the composer inserts and the parser round-trips.
 */
function mentionToken(User $user): string
{
    return "@[{$user->name}]({$user->id})";
}

test('posting a message persists a mention row for a real team member', function (): void {
    [$owner, $team, $general] = mentionTeamWithGeneral();
    $mentioned = teamMember($team, 'Ada Lovelace');

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => 'hey '.mentionToken($mentioned).' welcome',
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertRedirect();

    $message = Message::firstOrFail();

    $this->assertDatabaseHas('mentions', [
        'message_id' => $message->id,
        'mentioned_user_id' => $mentioned->id,
    ]);
    expect($message->mentionedUsers)->toHaveCount(1);
});

test('parsing resolves only real team members, ignoring non-members and unknown ids', function (): void {
    [$owner, $team, $general] = mentionTeamWithGeneral();
    $member = teamMember($team, 'Real Member');
    $stranger = User::factory()->create(['name' => 'Outsider']);
    $bogusId = (string) Str::uuid7();

    $body = mentionToken($member).' '.mentionToken($stranger)." @[Ghost]({$bogusId})";

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => $body,
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertRedirect();

    $message = Message::firstOrFail();

    expect($message->mentionedUsers->pluck('id')->all())->toBe([$member->id]);
    $this->assertDatabaseMissing('mentions', ['mentioned_user_id' => $stranger->id]);
});

test('a user mentioned twice in one message is persisted once', function (): void {
    [$owner, $team, $general] = mentionTeamWithGeneral();
    $mentioned = teamMember($team);

    $this->actingAs($owner)
        ->post(route('channels.messages.store', ['team' => $team->slug, 'channel' => $general->slug]), [
            'body' => mentionToken($mentioned).' and again '.mentionToken($mentioned),
            'client_uuid' => (string) Str::uuid7(),
        ])
        ->assertRedirect();

    expect(Message::firstOrFail()->mentionedUsers)->toHaveCount(1);
});

test('editing a message re-syncs mentions: adds new and removes stale', function (): void {
    [$owner, $team, $general] = mentionTeamWithGeneral();
    $first = teamMember($team, 'First Person');
    $second = teamMember($team, 'Second Person');

    $message = Message::factory()->for($general)->for($owner)->create([
        'body' => 'ping '.mentionToken($first),
    ]);
    $message->mentionedUsers()->sync([$first->id]);

    $this->actingAs($owner)
        ->patch(route('channels.messages.update', [
            'team' => $team->slug,
            'channel' => $general->slug,
            'message' => $message->id,
        ]), ['body' => 'ping '.mentionToken($second)])
        ->assertRedirect();

    expect($message->refresh()->mentionedUsers->pluck('id')->all())->toBe([$second->id]);
    $this->assertDatabaseMissing('mentions', ['mentioned_user_id' => $first->id]);
    $this->assertDatabaseHas('mentions', ['mentioned_user_id' => $second->id]);
});

test('the mention payload rides the MessageData on the channel page', function (): void {
    [$owner, $team, $general] = mentionTeamWithGeneral();
    $mentioned = teamMember($team, 'Grace Hopper');

    $message = Message::factory()->for($general)->for($owner)->create([
        'body' => 'hi '.mentionToken($mentioned),
    ]);
    $message->mentionedUsers()->sync([$mentioned->id]);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('messages.data.0.mentions', 1)
            ->where('messages.data.0.mentions.0.id', $mentioned->id)
            ->where('messages.data.0.mentions.0.name', 'Grace Hopper')
        );
});

test('the mention payload rides the broadcast MessageData', function (): void {
    [$owner, $team, $general] = mentionTeamWithGeneral();
    $mentioned = teamMember($team, 'Alan Turing');
    $clientUuid = (string) Str::uuid7();

    $this->actingAs($owner)->post(route('channels.messages.store', [
        'team' => $team->slug,
        'channel' => $general->slug,
    ]), [
        'body' => 'yo '.mentionToken($mentioned),
        'client_uuid' => $clientUuid,
    ]);

    $message = Message::where('client_uuid', $clientUuid)->firstOrFail();
    $payload = MessageData::fromMessage($message->load(['user', 'mentionedUsers']))->toArray();

    expect($payload['mentions'])->toBe([['id' => $mentioned->id, 'name' => 'Alan Turing']]);
});

test('a deleted message blanks its mentions in the tombstone payload', function (): void {
    [$owner, $team, $general] = mentionTeamWithGeneral();
    $mentioned = teamMember($team);

    $message = Message::factory()->for($general)->for($owner)->create();
    $message->mentionedUsers()->sync([$mentioned->id]);
    $message->delete();

    $payload = MessageData::fromMessage($message->load('user'))->toArray();

    expect($payload['isDeleted'])->toBeTrue()
        ->and($payload['mentions'])->toBe([]);
});

test('the channel page exposes team members for the composer autocomplete', function (): void {
    [$owner, $team, $general] = mentionTeamWithGeneral();
    teamMember($team, 'Bea Member');

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $general->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('members', 2)
            ->where('members', fn ($members): bool => collect($members)->pluck('name')->contains('Bea Member')
                && collect($members)->pluck('name')->contains($owner->name))
        );
});
