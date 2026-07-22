<?php

use App\Enums\PresenceState;
use App\Support\PresenceRegistry;
use Illuminate\Contracts\Cache\Repository;

beforeEach(function (): void {
    $this->registry = app(PresenceRegistry::class);
});

test('a user with no reported connection reads as active', function (): void {
    expect($this->registry->aggregate('user-1'))->toBe(PresenceState::Active);
});

test('a single idle connection makes the user away', function (): void {
    $this->registry->record('user-1', 'tab-a', PresenceState::Away);

    expect($this->registry->aggregate('user-1'))->toBe(PresenceState::Away);
});

test('one active connection keeps the user active while another is idle', function (): void {
    $this->registry->record('user-1', 'laptop', PresenceState::Active);
    $this->registry->record('user-1', 'phone', PresenceState::Away);

    expect($this->registry->aggregate('user-1'))->toBe(PresenceState::Active);
});

test('a connection reporting again replaces its previous state', function (): void {
    $this->registry->record('user-1', 'laptop', PresenceState::Active);
    $this->registry->record('user-1', 'laptop', PresenceState::Away);

    expect($this->registry->aggregate('user-1'))->toBe(PresenceState::Away);
});

test('releasing the last idle connection returns the user to active', function (): void {
    $this->registry->record('user-1', 'laptop', PresenceState::Away);
    $this->registry->forget('user-1', 'laptop');

    expect($this->registry->aggregate('user-1'))->toBe(PresenceState::Active);
});

test('releasing an idle tab leaves the other tabs deciding', function (): void {
    $this->registry->record('user-1', 'laptop', PresenceState::Active);
    $this->registry->record('user-1', 'phone', PresenceState::Away);

    $this->registry->forget('user-1', 'laptop');

    expect($this->registry->aggregate('user-1'))->toBe(PresenceState::Away);
});

test('connections are scoped per user', function (): void {
    $this->registry->record('user-1', 'tab-a', PresenceState::Away);

    expect($this->registry->aggregate('user-2'))->toBe(PresenceState::Active);
});

test('a connection that stopped reporting ages out and stops holding the user away', function (): void {
    config()->set('presence.away_after_minutes', 10);

    $this->registry->record('user-1', 'crashed-tab', PresenceState::Away);

    $this->travel(16)->minutes();

    expect($this->registry->aggregate('user-1'))->toBe(PresenceState::Active);
});

test('a connection still reporting within the window keeps its state', function (): void {
    config()->set('presence.away_after_minutes', 10);

    $this->registry->record('user-1', 'tab-a', PresenceState::Away);

    $this->travel(14)->minutes();

    expect($this->registry->aggregate('user-1'))->toBe(PresenceState::Away);
});

test('forgetting an unknown connection is a no-op', function (): void {
    $this->registry->record('user-1', 'laptop', PresenceState::Away);

    $this->registry->forget('user-1', 'never-seen');

    expect($this->registry->aggregate('user-1'))->toBe(PresenceState::Away);
});

test('an unreachable cache reads as active rather than failing the page', function (): void {
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('get')->andThrow(new RuntimeException('redis is down'));

    expect((new PresenceRegistry($cache))->aggregate('user-1'))->toBe(PresenceState::Active);
});

test('an unreachable cache swallows a write, which the next heartbeat re-states', function (): void {
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('get')->andReturn([]);
    $cache->shouldReceive('put')->andThrow(new RuntimeException('redis is down'));

    $registry = new PresenceRegistry($cache);

    $registry->record('user-1', 'tab-a', PresenceState::Away);

    expect($registry->aggregate('user-1'))->toBe(PresenceState::Active);
});

test('an unreachable cache swallows a release too', function (): void {
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('get')->andReturn(['tab-a' => ['state' => 'away', 'last_seen' => now()->getTimestamp()]]);
    $cache->shouldReceive('forget')->andThrow(new RuntimeException('redis is down'));

    $registry = new PresenceRegistry($cache);

    $registry->forget('user-1', 'tab-a');
})->throwsNoExceptions();
