<?php

use Inertia\Testing\AssertableInertia as Assert;

afterEach(function (): void {
    putenv('DEMO_MODE');
    unset($_ENV['DEMO_MODE'], $_SERVER['DEMO_MODE']);
});

test('demo mode is off by default', function (): void {
    expect(config('demo.mode'))->toBeFalse();
});

test('the shared demoMode prop is false when demo mode is off', function (): void {
    $this->reloadWithDemoMode(false);

    $this->get(route('home'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('Welcome')
        ->where('demoMode', false),
    );
});

test('the shared demoMode prop is true when demo mode is on', function (): void {
    $this->reloadWithDemoMode(true);

    $this->get(route('home'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('Welcome')
        ->where('demoMode', true),
    );
});

test('demo mode forces self-registration off even when registration is enabled', function (): void {
    $this->reloadWithEnv(['REGISTRATION_ENABLED' => true, 'DEMO_MODE' => true]);

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
});
