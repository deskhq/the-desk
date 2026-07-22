<?php

use App\Events\UserProfileUpdated;
use App\Models\User;
use Illuminate\Support\Facades\Event;

test('a user can pause notifications until an instant', function (): void {
    Event::fake([UserProfileUpdated::class]);
    $user = User::factory()->create();
    // The column stores whole seconds, so compare against a whole-second instant.
    $until = now()->addMinutes(30)->startOfSecond();

    $this->actingAs($user)
        ->put(route('dnd.update'), ['until' => $until->toIso8601String()])
        ->assertRedirect();

    expect($user->refresh()->dnd_until->equalTo($until))->toBeTrue();

    Event::assertDispatched(UserProfileUpdated::class);
});

test('pausing again replaces the running pause', function (): void {
    $user = User::factory()->create(['dnd_until' => now()->addMinutes(30)]);
    $until = now()->addHour()->startOfSecond();

    $this->actingAs($user)
        ->put(route('dnd.update'), ['until' => $until->toIso8601String()])
        ->assertRedirect();

    expect($user->refresh()->dnd_until->equalTo($until))->toBeTrue();
});

test('a pause instant is required and must be ahead', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('dnd.update'), [])
        ->assertSessionHasErrors('until');

    $this->actingAs($user)
        ->put(route('dnd.update'), ['until' => now()->subMinute()->toIso8601String()])
        ->assertSessionHasErrors('until');
});

test('a user can resume notifications, ending the pause early', function (): void {
    Event::fake([UserProfileUpdated::class]);
    $user = User::factory()->create(['dnd_until' => now()->addHour()]);

    $this->actingAs($user)
        ->delete(route('dnd.destroy'))
        ->assertRedirect();

    expect($user->refresh()->dnd_until)->toBeNull();

    Event::assertDispatched(UserProfileUpdated::class);
});

test('resuming leaves the quiet-hours schedule untouched', function (): void {
    $user = User::factory()->create([
        'dnd_until' => now()->addHour(),
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '22:00',
        'dnd_ends_at' => '07:00',
    ]);

    $this->actingAs($user)->delete(route('dnd.destroy'))->assertRedirect();

    $user->refresh();

    expect($user->dnd_schedule_enabled)->toBeTrue()
        ->and($user->dnd_starts_at)->toBe('22:00')
        ->and($user->dnd_ends_at)->toBe('07:00');
});

test('a guest cannot pause notifications', function (): void {
    $this->put(route('dnd.update'), ['until' => now()->addHour()->toIso8601String()])
        ->assertRedirect(route('login'));
});
