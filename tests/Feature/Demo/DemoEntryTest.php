<?php

declare(strict_types=1);

use App\Actions\Teams\CreateTeam;
use App\Models\User;
use Database\Seeders\DemoSeeder;

/**
 * Stand up the shared demo account inside its workspace — the shape
 * `demo:seed` leaves behind, reduced to what the entry route needs.
 */
function seededDemoAccount(): User
{
    $user = User::factory()->create(['email' => DemoSeeder::DEMO_EMAIL]);

    app(CreateTeam::class)->handle($user, DemoSeeder::TEAM_NAME);

    return $user->refresh();
}

test('the demo entry route signs a visitor in as the shared account and lands them in the workspace', function (): void {
    $this->reloadWithDemoMode(true);

    $demo = seededDemoAccount();

    $this->post(route('demo.login'))
        ->assertRedirect(route('channels.index', ['team' => $demo->currentTeam->slug], absolute: false));

    $this->assertAuthenticatedAs($demo);
});

test('the demo entry route regenerates the session so each visitor gets a fresh id', function (): void {
    $this->reloadWithDemoMode(true);

    seededDemoAccount();

    $this->startSession();
    $before = session()->getId();

    $this->post(route('demo.login'));

    expect(session()->getId())->not->toBe($before);
});

test('the demo entry route is unavailable when demo mode is off', function (): void {
    $this->reloadWithDemoMode(false);

    seededDemoAccount();

    $this->post(route('demo.login'))->assertNotFound();

    $this->assertGuest();
});

test('the demo entry route sends the visitor back with an error when the demo account is not seeded', function (): void {
    $this->reloadWithDemoMode(true);

    $this->from(route('login'))
        ->post(route('demo.login'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('demo mode rate-limits demo entry by IP', function (): void {
    $this->reloadWithDemoMode(true);

    seededDemoAccount();

    foreach (range(1, 10) as $ignored) {
        $this->post(route('demo.login'))->assertStatus(302);
    }

    $this->post(route('demo.login'))->assertStatus(429);

    // A different IP gets its own bucket, proving the throttle is keyed by IP
    // rather than by the (shared) demo account.
    $this->call('POST', route('demo.login'), [], [], [], ['REMOTE_ADDR' => '203.0.113.9'])
        ->assertStatus(302);
});
