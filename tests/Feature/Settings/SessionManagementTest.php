<?php

use App\Models\User;
use App\Support\SessionRegistry;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The owned session index that backs device management, independent of the
 * configured session driver.
 */
function sessionRegistry(): SessionRegistry
{
    return app(SessionRegistry::class);
}

/**
 * Record a session in the registry for the given user.
 */
function seedSession(User $user, string $id, string $userAgent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0.0.0 Safari/537.36', string $ip = '203.0.113.10', ?int $lastActivity = null): void
{
    sessionRegistry()->record($user->id, $id, $ip, $userAgent, $lastActivity);
}

test('the current device is listed even with no prior index entries', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->withCookie(config('session.cookie'), $currentId)
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0.0.0 Safari/537.36'])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/Security')
            ->has('sessions', 1)
            ->where('sessions.0.id', $currentId)
            ->where('sessions.0.isCurrentDevice', true)
            ->where('sessions.0.browser', 'Chrome')
            ->where('sessions.0.platform', 'Windows'),
        );
});

test('active sessions are listed with the current device flagged first', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);
    $otherId = Str::random(40);

    seedSession($user, $otherId, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) Version/17.2 Mobile Safari/604.1', ip: '203.0.113.10', lastActivity: now()->subHour()->timestamp);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->withCookie(config('session.cookie'), $currentId)
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0.0.0 Safari/537.36'])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/Security')
            ->has('sessions', 2)
            ->where('sessions.0.id', $currentId)
            ->where('sessions.0.isCurrentDevice', true)
            ->where('sessions.0.browser', 'Chrome')
            ->where('sessions.0.platform', 'Windows')
            ->where('sessions.1.id', $otherId)
            ->where('sessions.1.isCurrentDevice', false)
            ->where('sessions.1.browser', 'Safari')
            ->where('sessions.1.platform', 'iOS')
            ->where('sessions.1.ipAddress', '203.0.113.10'),
        );
});

test('sessions inactive beyond the session lifetime are not listed', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);
    $staleId = Str::random(40);

    seedSession($user, $staleId, lastActivity: now()->subMinutes((int) config('session.lifetime') + 1)->timestamp);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->withCookie(config('session.cookie'), $currentId)
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('sessions', 1)
            ->where('sessions.0.id', $currentId),
        );
});

test('a single session can be revoked', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);
    $otherId = Str::random(40);

    seedSession($user, $currentId);
    seedSession($user, $otherId);

    $this->actingAs($user)
        ->withSession(['active_session_id' => $currentId])
        ->withCookie(config('session.cookie'), $currentId)
        ->from(route('security.edit'))
        ->delete(route('sessions.destroy', $otherId), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Session revoked.']);

    expect(sessionRegistry()->has($user->id, $otherId))->toBeFalse();
    expect(sessionRegistry()->has($user->id, $currentId))->toBeTrue();
});

test('a revoked session can no longer make authenticated requests', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);
    $otherId = Str::random(40);

    seedSession($user, $currentId);
    seedSession($user, $otherId);

    $this->actingAs($user)
        ->withSession(['active_session_id' => $currentId])
        ->withCookie(config('session.cookie'), $currentId)
        ->delete(route('sessions.destroy', $otherId), ['password' => 'password'])
        ->assertRedirect();

    // The revoked device's next request is bounced to login and left a guest.
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time(), 'active_session_id' => $otherId])
        ->withCookie(config('session.cookie'), $otherId)
        ->get(route('security.edit'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('the current session cannot be revoked through the single-session route', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);

    seedSession($user, $currentId);

    $this->actingAs($user)
        ->withSession(['active_session_id' => $currentId])
        ->withCookie(config('session.cookie'), $currentId)
        ->from(route('security.edit'))
        ->delete(route('sessions.destroy', $currentId), ['password' => 'password'])
        ->assertRedirect(route('security.edit'))
        ->assertInertiaFlashMissing('toast');

    expect(sessionRegistry()->has($user->id, $currentId))->toBeTrue();
});

test('revoking an unknown session flashes no success toast', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);

    seedSession($user, $currentId);

    $this->actingAs($user)
        ->withSession(['active_session_id' => $currentId])
        ->withCookie(config('session.cookie'), $currentId)
        ->from(route('security.edit'))
        ->delete(route('sessions.destroy', Str::random(40)), ['password' => 'password'])
        ->assertRedirect(route('security.edit'))
        ->assertInertiaFlashMissing('toast');
});

test('a session belonging to another user cannot be revoked', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $currentId = Str::random(40);
    $victimId = Str::random(40);

    seedSession($user, $currentId);
    seedSession($otherUser, $victimId);

    $this->actingAs($user)
        ->withSession(['active_session_id' => $currentId])
        ->withCookie(config('session.cookie'), $currentId)
        ->from(route('security.edit'))
        ->delete(route('sessions.destroy', $victimId), ['password' => 'password'])
        ->assertRedirect(route('security.edit'))
        ->assertInertiaFlashMissing('toast');

    expect(sessionRegistry()->has($otherUser->id, $victimId))->toBeTrue();
});

test('logging out other devices removes other sessions but keeps the current one', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);
    $otherId = Str::random(40);
    $anotherId = Str::random(40);

    seedSession($user, $currentId);
    seedSession($user, $otherId);
    seedSession($user, $anotherId);

    $this->actingAs($user)
        ->withSession(['active_session_id' => $currentId])
        ->withCookie(config('session.cookie'), $currentId)
        ->from(route('security.edit'))
        ->delete(route('sessions.destroy-others'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Logged out of your other devices.']);

    expect(sessionRegistry()->has($user->id, $currentId))->toBeTrue();
    expect(sessionRegistry()->has($user->id, $otherId))->toBeFalse();
    expect(sessionRegistry()->has($user->id, $anotherId))->toBeFalse();
});

test('logging out other devices with no other devices flashes no toast', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);

    seedSession($user, $currentId);

    $this->actingAs($user)
        ->withSession(['active_session_id' => $currentId])
        ->withCookie(config('session.cookie'), $currentId)
        ->from(route('security.edit'))
        ->delete(route('sessions.destroy-others'), ['password' => 'password'])
        ->assertRedirect(route('security.edit'))
        ->assertInertiaFlashMissing('toast');

    expect(sessionRegistry()->has($user->id, $currentId))->toBeTrue();
});

test('a regenerated session id keeps the user signed in and moves its index entry', function (): void {
    $user = User::factory()->create();
    $oldId = Str::random(40);
    $newId = Str::random(40);

    seedSession($user, $oldId);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time(), 'active_session_id' => $oldId])
        ->withCookie(config('session.cookie'), $newId)
        ->get(route('security.edit'))
        ->assertOk();

    expect(sessionRegistry()->has($user->id, $oldId))->toBeFalse();
    expect(sessionRegistry()->has($user->id, $newId))->toBeTrue();
});

test('revoking a session requires the correct password', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);
    $otherId = Str::random(40);

    seedSession($user, $currentId);
    seedSession($user, $otherId);

    $this->actingAs($user)
        ->withSession(['active_session_id' => $currentId])
        ->withCookie(config('session.cookie'), $currentId)
        ->from(route('security.edit'))
        ->delete(route('sessions.destroy', $otherId), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('security.edit'));

    expect(sessionRegistry()->has($user->id, $otherId))->toBeTrue();
});

test('logging out other devices requires the correct password', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);
    $otherId = Str::random(40);

    seedSession($user, $currentId);
    seedSession($user, $otherId);

    $this->actingAs($user)
        ->withSession(['active_session_id' => $currentId])
        ->withCookie(config('session.cookie'), $currentId)
        ->from(route('security.edit'))
        ->delete(route('sessions.destroy-others'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('security.edit'));

    expect(sessionRegistry()->has($user->id, $otherId))->toBeTrue();
});
