<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('registration screen can be rendered', function (): void {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('registration screen includes team invitation context', function (): void {
    config(['app.url' => 'https://desk.acme.co']);

    $owner = User::factory()->create(['name' => 'Jonas Weber']);
    $team = Team::factory()->create(['name' => 'Laravel Team']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->get(route('register', ['invitation' => $invitation->code]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->component('auth/Register')
        ->where('teamInvitation.code', $invitation->code)
        ->where('teamInvitation.teamName', 'Laravel Team')
        ->where('teamInvitation.email', 'invited@example.com')
        ->where('teamInvitation.inviterName', 'Jonas Weber')
        ->where('teamInvitation.memberCount', 2)
        ->where('teamInvitation.hostDomain', 'desk.acme.co'),
    );
});

test('the invitation context withholds channel and roster details', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this->get(route('register', ['invitation' => $invitation->code]));

    $response->assertInertia(fn (Assert $page): Assert => $page
        ->missing('teamInvitation.channelNames')
        ->missing('teamInvitation.channelCount')
        ->missing('teamInvitation.onlineCount')
        ->missing('teamInvitation.members'),
    );
});

test('registering under an invitation rejects a different email address', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'someone.else@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $invitation->code,
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
    expect(User::where('email', 'someone.else@example.com')->exists())->toBeFalse();
});

test('registering under an invitation accepts the invited email address', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'INVITED@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $invitation->code,
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticated();
});

test('an unknown invitation code does not block registration', function (): void {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => 'no-such-code',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticated();
});

test('the register screen omits the consent links when they are not configured', function (): void {
    config(['legal.terms_url' => null, 'legal.privacy_url' => null]);

    $this->get(route('register'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('auth/Register')
            ->where('termsUrl', null)
            ->where('privacyUrl', null),
        );
});

test('the register screen omits the consent links when only one is configured', function (): void {
    config(['legal.terms_url' => 'https://acme.co/terms', 'legal.privacy_url' => null]);

    $this->get(route('register'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('termsUrl', null)
            ->where('privacyUrl', null),
        );
});

test('the register screen exposes the consent links when both are configured', function (): void {
    config([
        'legal.terms_url' => 'https://acme.co/terms',
        'legal.privacy_url' => 'https://acme.co/privacy',
    ]);

    $this->get(route('register'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('termsUrl', 'https://acme.co/terms')
            ->where('privacyUrl', 'https://acme.co/privacy'),
        );
});

test('registration requires consent once the legal links are configured', function (): void {
    config([
        'legal.terms_url' => 'https://acme.co/terms',
        'legal.privacy_url' => 'https://acme.co/privacy',
    ]);

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('terms');

    $this->assertGuest();

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => true,
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticated();
});

test('registration does not ask for consent when the legal links are unset', function (): void {
    config(['legal.terms_url' => null, 'legal.privacy_url' => null]);

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticated();
});

test('new users can register', function (): void {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();

    $user = User::where('email', 'test@example.com')->first();
    $response->assertRedirect(route('channels.index', ['team' => $user->currentTeam->slug]));
});
