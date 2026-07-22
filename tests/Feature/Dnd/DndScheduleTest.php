<?php

use App\Events\UserProfileUpdated;
use App\Models\User;
use Illuminate\Support\Facades\Event;

test('a user can enable a daily quiet-hours window', function (): void {
    Event::fake([UserProfileUpdated::class]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('dnd-schedule.update'), [
            'enabled' => true,
            'starts_at' => '22:00',
            'ends_at' => '07:00',
        ])
        ->assertRedirect();

    $user->refresh();

    expect($user->dnd_schedule_enabled)->toBeTrue()
        ->and($user->dnd_starts_at)->toBe('22:00')
        ->and($user->dnd_ends_at)->toBe('07:00');

    Event::assertDispatched(UserProfileUpdated::class);
});

test('disabling the schedule keeps the stored window for re-enabling', function (): void {
    Event::fake([UserProfileUpdated::class]);
    $user = User::factory()->create([
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '22:00',
        'dnd_ends_at' => '07:00',
    ]);

    $this->actingAs($user)
        ->put(route('dnd-schedule.update'), ['enabled' => false])
        ->assertRedirect();

    $user->refresh();

    expect($user->dnd_schedule_enabled)->toBeFalse()
        ->and($user->dnd_starts_at)->toBe('22:00')
        ->and($user->dnd_ends_at)->toBe('07:00');

    Event::assertDispatched(UserProfileUpdated::class);
});

test('a disable carrying bounds cannot clobber the stored window', function (): void {
    $user = User::factory()->create([
        'dnd_schedule_enabled' => true,
        'dnd_starts_at' => '22:00',
        'dnd_ends_at' => '07:00',
    ]);

    $this->actingAs($user)
        ->put(route('dnd-schedule.update'), [
            'enabled' => false,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
        ])
        ->assertRedirect();

    $user->refresh();

    expect($user->dnd_schedule_enabled)->toBeFalse()
        ->and($user->dnd_starts_at)->toBe('22:00')
        ->and($user->dnd_ends_at)->toBe('07:00');
});

test('enabling requires both window bounds', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('dnd-schedule.update'), ['enabled' => true])
        ->assertSessionHasErrors(['starts_at', 'ends_at']);
});

test('the window bounds must be wall-clock times', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('dnd-schedule.update'), [
            'enabled' => true,
            'starts_at' => '25:99',
            'ends_at' => 'noon',
        ])
        ->assertSessionHasErrors(['starts_at', 'ends_at']);
});

test('an empty window is rejected', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('dnd-schedule.update'), [
            'enabled' => true,
            'starts_at' => '09:00',
            'ends_at' => '09:00',
        ])
        ->assertSessionHasErrors('ends_at');
});

test('a guest cannot change the schedule', function (): void {
    $this->put(route('dnd-schedule.update'), ['enabled' => true])
        ->assertRedirect(route('login'));
});
