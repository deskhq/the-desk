<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\TeamRole;
use App\Models\AuditActivity;
use App\Models\Team;
use App\Support\AuditLog;
use App\Support\SimplePage;

/*
|--------------------------------------------------------------------------
| The audit-log read-model (#1199)
|--------------------------------------------------------------------------
|
| `AuditController` and `SecurityLogController` were line-for-line twins across
| seven concerns — the page size, the two filters, the `when` pair, the
| `simplePaginate` walk, the envelope and a fourteen-line actor lookup written
| out twice. The two logs genuinely differ in scope and filter column, so this
| is one of two read-models sharing three modules rather than one parameterised
| log.
|
| Constructed from a team and its two filters — never a `Request` — so every
| reading below is reachable without an HTTP round-trip, the bar ADR-0012 named.
| `tests/Feature/Teams/AuditLogTest.php` keeps the HTTP half.
|
*/

test('the log is constructible from a team alone and reads its own entries', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();

    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::ChannelCreated)->create();
    AuditActivity::factory()->forTeam(Team::factory()->create())->ofAction(AuditAction::TeamRenamed)->create();

    $entries = new AuditLog($team)->entries()->toArray();

    expect($entries['data'])->toHaveCount(1)
        ->and($entries['data'][0]['action'])->toBe(AuditAction::ChannelCreated->value)
        ->and($entries['prevPageUrl'])->toBeNull()
        ->and($entries['nextPageUrl'])->toBeNull();
});

test('the action filter narrows the log to one kind of entry', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();

    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::ChannelCreated)->create();
    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::MemberRemoved)->create();

    $entries = new AuditLog($team, action: AuditAction::MemberRemoved->value)->entries()->toArray();

    expect(array_column($entries['data'], 'action'))->toBe([AuditAction::MemberRemoved->value]);
});

test('the actor filter narrows the log to one person', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $admin = teamMemberInChannel($general, role: TeamRole::Admin);

    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::ChannelCreated)->create();
    AuditActivity::factory()->forTeam($team)->causedBy($admin)->ofAction(AuditAction::MemberRemoved)->create();

    $entries = new AuditLog($team, actor: $admin->id)->entries()->toArray();

    expect(array_column($entries['data'], 'actorName'))->toBe([$admin->name]);
});

test('the actor facet offers only the people the log names', function (): void {
    ['owner' => $owner, 'team' => $team, 'channel' => $general] = teamWithChannel();
    $admin = teamMemberInChannel($general, role: TeamRole::Admin);
    teamMemberInChannel($general, role: TeamRole::Admin);

    AuditActivity::factory()->forTeam($team)->causedBy($admin)->ofAction(AuditAction::MemberRemoved)->create();
    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::ChannelCreated)->create();
    // Recorded with no human causer, so there is nobody to offer as a choice.
    AuditActivity::factory()->forTeam($team)->uncaused()->ofAction(AuditAction::WebhookSubscriptionAutoDisabled)->create();

    $actors = new AuditLog($team)->actors();

    expect($actors)->toHaveCount(2)
        ->and(array_column($actors, 'name'))->toBe(collect([$owner->name, $admin->name])->sort()->values()->all());
});

test('a page is capped and carries the link to the next one', function (): void {
    ['owner' => $owner, 'team' => $team] = teamWithChannel();

    AuditActivity::factory()->forTeam($team)->causedBy($owner)->ofAction(AuditAction::ChannelCreated)
        ->count(SimplePage::PER_PAGE + 5)
        ->create();

    $entries = new AuditLog($team)->entries()->toArray();

    expect($entries['data'])->toHaveCount(SimplePage::PER_PAGE)
        ->and($entries['nextPageUrl'])->toContain('page=2')
        ->and($entries['prevPageUrl'])->toBeNull();
});
