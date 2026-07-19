<?php

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(function (): void {
    putenv('DEMO_MODE');
    unset($_ENV['DEMO_MODE'], $_SERVER['DEMO_MODE']);
});

test('the login page exposes a pending invitation when the code is valid', function (): void {
    $team = Team::factory()->create(['name' => 'Laravel Team']);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => User::factory()->create()->id,
    ]);

    $this->get(route('login', ['invitation' => $invitation->code]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('auth/Login')
            ->where('teamInvitation.code', $invitation->code)
            ->where('teamInvitation.teamName', 'Laravel Team'),
        );
});

test('the login page ignores an unknown invitation code', function (): void {
    $this->get(route('login', ['invitation' => 'unknown-code']))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('auth/Login')
            ->where('teamInvitation', null),
        );
});

test('the login page advertises the shared demo credentials on the demo', function (): void {
    $this->reloadWithDemoMode(true);

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('auth/Login')
            ->where('demoCredentials.email', DemoSeeder::DEMO_EMAIL)
            ->where('demoCredentials.password', DemoSeeder::DEMO_PASSWORD),
        );
});

test('the login page hides the demo credentials off the demo', function (): void {
    $this->reloadWithDemoMode(false);

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('auth/Login')
            ->where('demoCredentials', null),
        );
});
