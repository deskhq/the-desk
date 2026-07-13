<?php

use App\Models\User;
use App\Support\Gravatar;

test('a user exposes its gravatar url as the avatar attribute', function (): void {
    config()->set('gravatar.enabled', true);

    $user = User::factory()->create(['email' => 'person@example.com']);

    expect($user->avatar)->toBe(Gravatar::url('person@example.com'));
});

test('the avatar is appended to the serialised user so every frontend surface receives it', function (): void {
    config()->set('gravatar.enabled', true);

    $user = User::factory()->create(['email' => 'person@example.com']);

    expect($user->toArray())
        ->toHaveKey('avatar', Gravatar::url('person@example.com'));
});

test('the avatar attribute is null when gravatar is disabled', function (): void {
    config()->set('gravatar.enabled', false);

    $user = User::factory()->create(['email' => 'person@example.com']);

    expect($user->avatar)->toBeNull();
});

test('a stored avatar_url takes precedence over the derived gravatar', function (): void {
    config()->set('gravatar.enabled', true);

    $user = User::factory()->create([
        'email' => 'person@example.com',
        'avatar_url' => 'https://cdn.example/uploaded.png',
    ]);

    expect($user->avatar)->toBe('https://cdn.example/uploaded.png');
});

test('a stored avatar_url is used even when gravatar is disabled', function (): void {
    config()->set('gravatar.enabled', false);

    $user = User::factory()->create([
        'email' => 'person@example.com',
        'avatar_url' => 'https://cdn.example/uploaded.png',
    ]);

    expect($user->avatar)->toBe('https://cdn.example/uploaded.png');
});
