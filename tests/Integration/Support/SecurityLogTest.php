<?php

declare(strict_types=1);

use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\SecurityLog;
use App\Support\SimplePage;

/*
|--------------------------------------------------------------------------
| The security-log read-model (#1199)
|--------------------------------------------------------------------------
|
| The sibling of `AuditLog`. They share the envelope and the actor facet and
| keep their own scope, and this one's scope is the reason they are two
| read-models rather than one parameterised log: a security event is recorded
| against an *account*, not a team, so the workspace log is a live join to the
| team's current membership. That rule used to live unnamed inside a controller
| and had no test of its own.
|
| Constructed from a team and its two filters — never a `Request` (ADR-0012).
| `tests/Feature/Teams/SecurityLogTest.php` keeps the HTTP half.
|
*/

test('the log is constructible from a team alone and reads its members events', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    $stranger = User::factory()->create();

    SecurityEvent::factory()->for($owner)->create();
    SecurityEvent::factory()->for($member)->create();
    SecurityEvent::factory()->for($stranger)->create();

    $events = new SecurityLog($team)->events()->toArray();

    expect($events['data'])->toHaveCount(2)
        ->and(array_column($events['data'], 'actorName'))->not->toContain($stranger->name)
        ->and($events['prevPageUrl'])->toBeNull()
        ->and($events['nextPageUrl'])->toBeNull();
});

/**
 * The membership rule, stated on its own. An account-level event surfaces in a
 * workspace's log only *while* its user is a member — the scope is a live join,
 * not a copy taken when the row was written — so removing someone drops their
 * history from this workspace's view of them at once.
 */
test('an event leaves the log the moment its user leaves the team', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    SecurityEvent::factory()->for($owner)->create();
    SecurityEvent::factory()->for($member)->create();

    expect(new SecurityLog($team)->events()->toArray()['data'])->toHaveCount(2);

    $team->members()->detach($member);

    $log = new SecurityLog($team);

    expect($log->events()->toArray()['data'])->toHaveCount(1)
        ->and(array_column($log->actors(), 'id'))->toBe([$owner->id]);
});

test('the type filter narrows the log to one kind of event', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();

    SecurityEvent::factory()->for($owner)->ofType(SecurityEventType::LoggedIn)->create();
    SecurityEvent::factory()->for($owner)->ofType(SecurityEventType::PasswordChanged)->create();

    $events = new SecurityLog($team, type: SecurityEventType::PasswordChanged->value)->events()->toArray();

    expect(array_column($events['data'], 'type'))->toBe([SecurityEventType::PasswordChanged->value]);
});

test('the actor filter narrows the log to one person', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);

    SecurityEvent::factory()->for($owner)->create();
    SecurityEvent::factory()->for($member)->create();

    $events = new SecurityLog($team, actor: $member->id)->events()->toArray();

    expect(array_column($events['data'], 'actorName'))->toBe([$member->name]);
});

test('the actor facet offers only the members the log names', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $member = teamMemberInChannel($general);
    teamMemberInChannel($general);
    $stranger = User::factory()->create();

    SecurityEvent::factory()->for($owner)->create();
    SecurityEvent::factory()->for($member)->create();
    SecurityEvent::factory()->for($stranger)->create();

    $actors = new SecurityLog($team)->actors();

    expect($actors)->toHaveCount(2)
        ->and(array_column($actors, 'name'))->toBe(collect([$owner->name, $member->name])->sort()->values()->all());
});

test('a page is capped and carries the link to the next one', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();

    SecurityEvent::factory()->for($owner)->count(SimplePage::PER_PAGE + 5)->create();

    $events = new SecurityLog($team)->events()->toArray();

    expect($events['data'])->toHaveCount(SimplePage::PER_PAGE)
        ->and($events['nextPageUrl'])->toContain('page=2')
        ->and($events['prevPageUrl'])->toBeNull();
});
