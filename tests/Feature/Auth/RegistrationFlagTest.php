<?php

use Inertia\Testing\AssertableInertia as Assert;

afterEach(function (): void {
    putenv('REGISTRATION_ENABLED');
    unset($_ENV['REGISTRATION_ENABLED'], $_SERVER['REGISTRATION_ENABLED']);
});

test('registration routes respond when registration is enabled', function (): void {
    $this->reloadWithRegistrationEnabled(true);

    $this->get('/register')->assertOk();

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect();
});

test('registration routes return 404 when registration is disabled', function (): void {
    $this->reloadWithRegistrationEnabled(false);

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
});

test('the shared registrationEnabled prop is true when registration is enabled', function (): void {
    $this->reloadWithRegistrationEnabled(true);

    $this->get(route('home'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('Welcome')
        ->where('registrationEnabled', true),
    );

    $this->get(route('login'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('auth/Login')
        ->where('registrationEnabled', true),
    );
});

test('the shared registrationEnabled prop is false when registration is disabled', function (): void {
    $this->reloadWithRegistrationEnabled(false);

    $this->get(route('home'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('Welcome')
        ->where('registrationEnabled', false),
    );

    $this->get(route('login'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('auth/Login')
        ->where('registrationEnabled', false),
    );
});
