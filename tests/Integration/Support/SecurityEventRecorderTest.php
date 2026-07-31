<?php

use App\Enums\SecurityEventType;
use App\Models\User;
use App\Support\SecurityEventRecorder;

/**
 * The device context of a sign-in, as the recorder receives it: an address and
 * a user agent, already captured. The listener that reads them off a live
 * request is deliberately not in the way here — that separation is the whole
 * reason this module resolves outside HTTP.
 *
 * @return array{0: string, 1: string}
 */
function officeDevice(): array
{
    return ['203.0.113.7', 'Mozilla/5.0 (Macintosh) TheDesk/1.0'];
}

test('an event is recorded against the user and the device context it was handed', function (): void {
    $user = User::factory()->create();
    [$ip, $agent] = officeDevice();

    $event = app(SecurityEventRecorder::class)
        ->record($user, SecurityEventType::PasswordChanged, $ip, $agent);

    expect($event->user_id)->toBe($user->id)
        ->and($event->type)->toBe(SecurityEventType::PasswordChanged)
        ->and($event->ip_address)->toBe($ip)
        ->and($event->user_agent)->toBe($agent);
});

test('the context is optional, for an event with no request behind it', function (): void {
    $user = User::factory()->create();

    $event = app(SecurityEventRecorder::class)
        ->record($user, SecurityEventType::AccountProvisioned, null, null);

    expect($event->ip_address)->toBeNull()
        ->and($event->user_agent)->toBeNull();
});

test('the first sign-in from an address and agent is a new device, and the next one is not', function (): void {
    $user = User::factory()->create();
    $recorder = app(SecurityEventRecorder::class);
    [$ip, $agent] = officeDevice();

    $first = $recorder->record($user, SecurityEventType::LoggedIn, $ip, $agent);
    $repeat = $recorder->record($user, SecurityEventType::LoggedIn, $ip, $agent);
    $elsewhere = $recorder->record($user, SecurityEventType::LoggedIn, '198.51.100.4', $agent);

    expect($first->is_new_device)->toBeTrue()
        ->and($repeat->is_new_device)->toBeFalse()
        ->and($elsewhere->is_new_device)->toBeTrue();
});

test('a device another account has signed in from is still new to this one', function (): void {
    $stranger = User::factory()->create();
    $user = User::factory()->create();
    $recorder = app(SecurityEventRecorder::class);
    [$ip, $agent] = officeDevice();

    $recorder->record($stranger, SecurityEventType::LoggedIn, $ip, $agent);

    expect($recorder->record($user, SecurityEventType::LoggedIn, $ip, $agent)->is_new_device)
        ->toBeTrue();
});

test('only a sign-in is ever flagged as a new device', function (): void {
    $user = User::factory()->create();
    [$ip, $agent] = officeDevice();

    $event = app(SecurityEventRecorder::class)
        ->record($user, SecurityEventType::PasskeyRegistered, $ip, $agent);

    expect($event->is_new_device)->toBeFalse();
});
