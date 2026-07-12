<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

// These tests exercise the verification flow itself, so they run with the
// EMAIL_VERIFICATION_ENABLED enforcement flag on (it defaults off).
beforeEach(fn () => config(['fortify.email_verification_enabled' => true]));

test('sends verification notification', function (): void {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('home'));

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('does not send verification notification if email is verified', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect('/');

    Notification::assertNothingSent();
});
