<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a guest cannot complete onboarding', function (): void {
    $this->patch(route('onboarding.update'))
        ->assertRedirect(route('login'));
});

test('completing onboarding records the completion time', function (): void {
    $user = User::factory()->notOnboarded()->create();

    expect($user->onboarding_completed_at)->toBeNull();

    $this
        ->actingAs($user)
        ->patch(route('onboarding.update'))
        ->assertSessionHasNoErrors();

    expect($user->refresh()->onboarding_completed_at)->not->toBeNull();
});

test('completing onboarding again keeps the original completion time', function (): void {
    $completedAt = now()->subWeek();
    $user = User::factory()->create(['onboarding_completed_at' => $completedAt]);

    $this
        ->actingAs($user)
        ->patch(route('onboarding.update'))
        ->assertSessionHasNoErrors();

    expect($user->refresh()->onboarding_completed_at->timestamp)
        ->toBe($completedAt->timestamp);
});

test('the onboarding completion flag is shared with the frontend', function (): void {
    $user = User::factory()->notOnboarded()->create();
    $team = $user->currentTeam;

    $this
        ->actingAs($user)
        ->get(route('channels.show', ['team' => $team->slug, 'channel' => 'general']))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('auth.user.onboarding_completed_at', null));
});
