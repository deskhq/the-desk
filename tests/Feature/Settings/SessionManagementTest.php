<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Insert a row into the `sessions` table for the given user.
 */
function seedSession(User $user, string $id, string $userAgent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0.0.0 Safari/537.36', string $ip = '203.0.113.10', ?int $lastActivity = null): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => $ip,
        'user_agent' => $userAgent,
        'payload' => '',
        'last_activity' => $lastActivity ?? now()->timestamp,
    ]);
}

test('active sessions are listed on the security page with the current device flagged', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);
    $otherId = Str::random(40);

    seedSession($user, $currentId, ip: '198.51.100.5', lastActivity: now()->subMinute()->timestamp);
    seedSession($user, $otherId, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) Version/17.2 Mobile Safari/604.1', ip: '203.0.113.10', lastActivity: now()->subHour()->timestamp);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->withCookie(config('session.cookie'), $currentId)
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/Security')
            ->has('sessions', 2)
            ->where('sessions.0.id', $currentId)
            ->where('sessions.0.isCurrentDevice', true)
            ->where('sessions.0.browser', 'Chrome')
            ->where('sessions.0.platform', 'Windows')
            ->where('sessions.0.ipAddress', '198.51.100.5')
            ->where('sessions.1.id', $otherId)
            ->where('sessions.1.isCurrentDevice', false),
        );
});

test('a single session can be revoked', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);
    $otherId = Str::random(40);

    seedSession($user, $currentId);
    seedSession($user, $otherId);

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $currentId)
        ->from(route('security.edit'))
        ->delete(route('sessions.destroy', $otherId), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    $this->assertDatabaseMissing('sessions', ['id' => $otherId]);
    $this->assertDatabaseHas('sessions', ['id' => $currentId]);
});

test('the current session cannot be revoked through the single-session route', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);

    seedSession($user, $currentId);

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $currentId)
        ->from(route('security.edit'))
        ->delete(route('sessions.destroy', $currentId), ['password' => 'password'])
        ->assertRedirect(route('security.edit'));

    $this->assertDatabaseHas('sessions', ['id' => $currentId]);
});

test('a session belonging to another user cannot be revoked', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $currentId = Str::random(40);
    $victimId = Str::random(40);

    seedSession($user, $currentId);
    seedSession($otherUser, $victimId);

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $currentId)
        ->from(route('security.edit'))
        ->delete(route('sessions.destroy', $victimId), ['password' => 'password'])
        ->assertRedirect(route('security.edit'));

    $this->assertDatabaseHas('sessions', ['id' => $victimId]);
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
        ->withCookie(config('session.cookie'), $currentId)
        ->from(route('security.edit'))
        ->delete(route('sessions.destroy-others'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    $this->assertDatabaseHas('sessions', ['id' => $currentId]);
    $this->assertDatabaseMissing('sessions', ['id' => $otherId]);
    $this->assertDatabaseMissing('sessions', ['id' => $anotherId]);
});

test('revoking a session requires the correct password', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);
    $otherId = Str::random(40);

    seedSession($user, $currentId);
    seedSession($user, $otherId);

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $currentId)
        ->from(route('security.edit'))
        ->delete(route('sessions.destroy', $otherId), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('security.edit'));

    $this->assertDatabaseHas('sessions', ['id' => $otherId]);
});

test('logging out other devices requires the correct password', function (): void {
    $user = User::factory()->create();
    $currentId = Str::random(40);
    $otherId = Str::random(40);

    seedSession($user, $currentId);
    seedSession($user, $otherId);

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $currentId)
        ->from(route('security.edit'))
        ->delete(route('sessions.destroy-others'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('security.edit'));

    $this->assertDatabaseHas('sessions', ['id' => $otherId]);
});
