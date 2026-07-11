<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Channel;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the workspace ships the team members for the DM entry points', function () {
    $owner = User::factory()->create(['name' => 'Zoe Owner']);
    $team = app(CreateTeam::class)->handle($owner, 'Acme');
    $member = User::factory()->create(['name' => 'Amy Member']);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $this->actingAs($owner)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => Channel::GENERAL_SLUG]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('teamMembers', 2)
            // Ordered by name: Amy before Zoe.
            ->where('teamMembers.0.name', 'Amy Member')
            ->where('teamMembers.0.id', $member->id)
            ->where('teamMembers.1.name', 'Zoe Owner')
        );
});

test('the team members prop is absent off the channel workspace', function () {
    $owner = User::factory()->create();
    app(CreateTeam::class)->handle($owner, 'Acme');

    $this->actingAs($owner)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('teamMembers', [])
        );
});
