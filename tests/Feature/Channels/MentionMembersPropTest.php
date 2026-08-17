<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelType;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Who the composer may offer for `@`, as the visit delivers them
|--------------------------------------------------------------------------
|
| The list itself is composed on the client (`lib/channelRoster.ts`) rather than
| shipped, so what a visit owes is the *inputs*: the workspace roster for a
| standard channel, the conversation's own participants for a direct message.
|
*/

test('a standard channel ships the whole team for mention autocomplete', function (): void {
    $owner = User::factory()->create(['name' => 'Zoe Owner']);
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $member = User::factory()->create(['name' => 'Amy Member']);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('teamMembers', 2)
            ->where('teamMembers.0.name', 'Amy Member')
            ->where('teamMembers.1.name', 'Zoe Owner')
        );
});

test('a direct message scopes mention autocomplete to its participants', function (): void {
    $owner = User::factory()->create(['name' => 'Zoe Owner']);
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $other = User::factory()->create(['name' => 'Amy Member']);
    $team->memberships()->create(['user_id' => $other->id, 'role' => TeamRole::Member]);
    $bystander = User::factory()->create(['name' => 'Bob Bystander']);
    $team->memberships()->create(['user_id' => $bystander->id, 'role' => TeamRole::Member]);

    $this->actingAs($owner)->post(route('channels.dm.store', ['team' => $team->slug]), ['user_id' => $other->id]);
    $dm = Channel::where('team_id', $team->id)->where('type', ChannelType::Direct)->firstOrFail();

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => $dm->slug]))
        ->assertInertia(fn (Assert $page): Assert => $page
            // Only the viewer's counterpart — the client adds the viewer
            // themselves; the bystander is not in the conversation and so is not
            // mentionable in it, whatever the workspace roster holds.
            ->has('channel.dmParticipants', 1)
            ->where('channel.dmParticipants.0.name', 'Amy Member')
        );
});
