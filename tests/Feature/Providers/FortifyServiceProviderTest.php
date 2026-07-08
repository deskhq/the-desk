<?php

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the login page exposes a pending invitation when the code is valid', function () {
    $team = Team::factory()->create(['name' => 'Laravel Team']);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => User::factory()->create()->id,
    ]);

    $this->get(route('login', ['invitation' => $invitation->code]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->where('teamInvitation.code', $invitation->code)
            ->where('teamInvitation.teamName', 'Laravel Team'),
        );
});

test('the login page ignores an unknown invitation code', function () {
    $this->get(route('login', ['invitation' => 'unknown-code']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->where('teamInvitation', null),
        );
});
