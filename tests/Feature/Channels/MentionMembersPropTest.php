<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\ChannelType;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a standard channel ships the whole team for mention autocomplete', function () {
    $owner = User::factory()->create(['name' => 'Zoe Owner']);
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $member = User::factory()->create(['name' => 'Amy Member']);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('members', 2)
            ->where('members.0.name', 'Amy Member')
            ->where('members.1.name', 'Zoe Owner')
        );
});

test('a direct message scopes mention autocomplete to its participants', function () {
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
        ->assertInertia(fn (Assert $page) => $page
            ->has('members', 2)
            // Only the two DM participants, ordered by name; the bystander is excluded.
            ->where('members.0.name', 'Amy Member')
            ->where('members.1.name', 'Zoe Owner')
        );
});
