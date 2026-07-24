<?php

use App\Actions\Teams\CreateTeam;
use App\Data\AuditExportData;
use App\Enums\AppLocale;
use App\Enums\AuditAction;
use App\Enums\AuditExportFormat;
use App\Enums\AuditExportLogType;
use App\Enums\AuditExportStatus;
use App\Enums\SecurityEventType;
use App\Enums\TeamRole;
use App\Jobs\GenerateAuditExport;
use App\Mail\AuditExportReady;
use App\Models\AuditActivity;
use App\Models\AuditExport;
use App\Models\SecurityEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a real (non-personal) team owned by a fresh user.
 *
 * @return array{0: User, 1: Team}
 */
function exportTeam(): array
{
    $owner = User::factory()->create();
    $team = app(CreateTeam::class)->handle($owner, 'Acme');

    return [$owner, $team];
}

/**
 * Attach a member to a team with the given role.
 */
function exportMember(Team $team, TeamRole $role = TeamRole::Member): User
{
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => $role->value]);

    return $member;
}

/**
 * Fetch the audit entry recording an export request for a team.
 */
function exportAuditEntry(Team $team): AuditActivity
{
    return AuditActivity::query()
        ->where('team_id', $team->id)
        ->where('event', AuditAction::AuditExported->value)
        ->sole();
}

// ── Page access ─────────────────────────────────────────────────────────────

test('an admin can view the exports page', function (): void {
    [, $team] = exportTeam();
    $admin = exportMember($team, TeamRole::Admin);
    AuditExport::factory()->for($team)->create(['requested_by' => $admin->id]);

    $this->actingAs($admin)
        ->get(route('teams.audit-exports.index', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/AuditExports')
            ->has('exports', 1)
            ->has('logTypeOptions', 2)
            ->has('formatOptions', 2));
});

test('the exports page lists exports newest first', function (): void {
    [$owner, $team] = exportTeam();
    $older = AuditExport::factory()->for($team)->create([
        'requested_by' => $owner->id,
        'created_at' => now()->subDay(),
    ]);
    $newer = AuditExport::factory()->for($team)->create([
        'requested_by' => $owner->id,
        'created_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('teams.audit-exports.index', $team))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('exports.0.id', $newer->id)
            ->where('exports.1.id', $older->id));
});

test('the exports page catalog carries the new-export intro line in french', function (): void {
    [, $team] = exportTeam();
    $admin = exportMember($team, TeamRole::Admin);
    $admin->update(['locale' => AppLocale::French->value]);

    $page = $this->actingAs($admin)
        ->get(route('teams.audit-exports.index', $team))
        ->viewData('page');

    expect($page['props']['translations'])
        ->toHaveKey("One log, one format, one file. You'll get an email when it's ready.");
});

test('a plain member cannot view the exports page', function (): void {
    [, $team] = exportTeam();
    $member = exportMember($team, TeamRole::Member);

    $this->actingAs($member)
        ->get(route('teams.audit-exports.index', $team))
        ->assertForbidden();
});

// ── Requesting an export ─────────────────────────────────────────────────────

test('requesting an audit export queues the job and records a pending export', function (): void {
    Queue::fake();

    [$owner, $team] = exportTeam();

    $this->actingAs($owner)
        ->from(route('teams.audit-exports.index', $team))
        ->post(route('teams.audit-exports.store', $team), [
            'log_type' => AuditExportLogType::Audit->value,
            'format' => AuditExportFormat::Csv->value,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('teams.audit-exports.index', $team));

    Queue::assertPushed(GenerateAuditExport::class);

    $export = $team->auditExports()->sole();

    expect($export->status)->toBe(AuditExportStatus::Pending);
    expect($export->requested_by)->toBe($owner->id);
    expect($export->log_type)->toBe(AuditExportLogType::Audit);

    $entry = exportAuditEntry($team);
    expect($entry->causer_id)->toBe($owner->id);
    expect($entry->properties['range'])->toBe('All time');
});

test('requesting a security export authorizes against the security-log policy', function (): void {
    Queue::fake();

    [$owner, $team] = exportTeam();

    $this->actingAs($owner)
        ->post(route('teams.audit-exports.store', $team), [
            'log_type' => AuditExportLogType::Security->value,
            'format' => AuditExportFormat::Json->value,
        ])
        ->assertRedirect();

    Queue::assertPushed(GenerateAuditExport::class);
    expect($team->auditExports()->sole()->log_type)->toBe(AuditExportLogType::Security);
});

test('a date range is recorded in the requester timezone whole days', function (): void {
    Queue::fake();

    [$owner, $team] = exportTeam();

    $this->actingAs($owner)
        ->post(route('teams.audit-exports.store', $team), [
            'log_type' => AuditExportLogType::Audit->value,
            'format' => AuditExportFormat::Csv->value,
            'range_start' => '2026-04-01',
            'range_end' => '2026-06-30',
        ])
        ->assertRedirect();

    $export = $team->auditExports()->sole();
    expect($export->range_start->toDateString())->toBe('2026-04-01');
    expect($export->range_end->toDateString())->toBe('2026-06-30');
    expect(exportAuditEntry($team)->properties['range'])->toBe('2026-04-01 – 2026-06-30');
});

test('an open-ended start range is labelled "from"', function (): void {
    Queue::fake();
    [$owner, $team] = exportTeam();

    $this->actingAs($owner)->post(route('teams.audit-exports.store', $team), [
        'log_type' => AuditExportLogType::Audit->value,
        'format' => AuditExportFormat::Csv->value,
        'range_start' => '2026-04-01',
    ])->assertRedirect();

    expect(exportAuditEntry($team)->properties['range'])->toBe(sprintf(__('From %s'), '2026-04-01'));
});

test('an open-ended end range is labelled "until"', function (): void {
    Queue::fake();
    [$owner, $team] = exportTeam();

    $this->actingAs($owner)->post(route('teams.audit-exports.store', $team), [
        'log_type' => AuditExportLogType::Audit->value,
        'format' => AuditExportFormat::Csv->value,
        'range_end' => '2026-06-30',
    ])->assertRedirect();

    expect(exportAuditEntry($team)->properties['range'])->toBe(sprintf(__('Until %s'), '2026-06-30'));
});

test('a second export is rejected while one is still generating', function (): void {
    Queue::fake();

    [$owner, $team] = exportTeam();
    AuditExport::factory()->for($team)->create([
        'requested_by' => $owner->id,
        'status' => AuditExportStatus::Pending,
    ]);

    $this->actingAs($owner)
        ->post(route('teams.audit-exports.store', $team), [
            'log_type' => AuditExportLogType::Audit->value,
            'format' => AuditExportFormat::Csv->value,
        ])
        ->assertRedirect();

    Queue::assertNothingPushed();
    expect($team->auditExports()->count())->toBe(1);
});

test('an end date before the start date is rejected', function (): void {
    [$owner, $team] = exportTeam();

    $this->actingAs($owner)
        ->from(route('teams.audit-exports.index', $team))
        ->post(route('teams.audit-exports.store', $team), [
            'log_type' => AuditExportLogType::Audit->value,
            'format' => AuditExportFormat::Csv->value,
            'range_start' => '2026-06-30',
            'range_end' => '2026-04-01',
        ])
        ->assertSessionHasErrors('range_end');

    expect($team->auditExports()->count())->toBe(0);
});

test('a plain member cannot request an export', function (): void {
    [, $team] = exportTeam();
    $member = exportMember($team, TeamRole::Member);

    $this->actingAs($member)
        ->post(route('teams.audit-exports.store', $team), [
            'log_type' => AuditExportLogType::Audit->value,
            'format' => AuditExportFormat::Csv->value,
        ])
        ->assertForbidden();
});

test('a failed queue dispatch marks the export failed and records no audit event', function (): void {
    [$owner, $team] = exportTeam();

    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down'));

    $this->actingAs($owner)
        ->from(route('teams.audit-exports.index', $team))
        ->post(route('teams.audit-exports.store', $team), [
            'log_type' => AuditExportLogType::Audit->value,
            'format' => AuditExportFormat::Csv->value,
        ])
        ->assertRedirect(route('teams.audit-exports.index', $team));

    expect($team->auditExports()->sole()->status)->toBe(AuditExportStatus::Failed);
    expect(AuditActivity::query()
        ->where('team_id', $team->id)
        ->where('event', AuditAction::AuditExported->value)
        ->count())->toBe(0);
});

// ── Generating the file ──────────────────────────────────────────────────────

test('the job writes an audit-log CSV and emails the requester', function (): void {
    Storage::fake('local');
    Mail::fake();

    [$owner, $team] = exportTeam();
    AuditActivity::factory()->forTeam($team)->ofAction(AuditAction::TeamRenamed)->causedBy($owner)->create([
        'properties' => ['old_name' => 'Acme', 'new_name' => 'Acme Corp'],
    ]);
    // An entry with no actor exercises the empty-cell path.
    AuditActivity::factory()->forTeam($team)->ofAction(AuditAction::ChannelCreated)->create([
        'causer_id' => null,
        'causer_type' => null,
        'properties' => ['channel_name' => 'general'],
    ]);

    $export = AuditExport::factory()->for($team)->create([
        'requested_by' => $owner->id,
        'log_type' => AuditExportLogType::Audit,
        'format' => AuditExportFormat::Csv,
    ]);

    (new GenerateAuditExport($export->id))->handle();

    $export->refresh();
    expect($export->status)->toBe(AuditExportStatus::Ready);
    expect($export->path)->toBe('audit-exports/'.$export->id.'.csv');
    expect($export->expires_at)->not->toBeNull();
    Storage::disk('local')->assertExists($export->path);

    $csv = Storage::disk('local')->get($export->path);
    expect($csv)->toContain('id,occurred_at,action,action_label,actor_name,actor_id,description,properties');
    expect($csv)->toContain('team_renamed');
    expect($csv)->toContain($owner->name);
    expect($csv)->toContain('Acme Corp');

    Mail::assertSent(AuditExportReady::class, fn (AuditExportReady $mail): bool => $mail->hasTo($owner->email));
});

test('the job writes an audit-log JSON document', function (): void {
    Storage::fake('local');
    Mail::fake();

    [$owner, $team] = exportTeam();
    AuditActivity::factory()->forTeam($team)->ofAction(AuditAction::TeamRenamed)->causedBy($owner)->create([
        'properties' => ['old_name' => 'Acme', 'new_name' => 'Acme Corp'],
    ]);

    $export = AuditExport::factory()->for($team)->json()->create([
        'requested_by' => $owner->id,
        'log_type' => AuditExportLogType::Audit,
    ]);

    (new GenerateAuditExport($export->id))->handle();

    $records = json_decode((string) Storage::disk('local')->get($export->refresh()->path), true);
    expect($records)->toHaveCount(1);
    expect($records[0]['action'])->toBe('team_renamed');
    expect($records[0]['properties'])->toBe(['old_name' => 'Acme', 'new_name' => 'Acme Corp']);
});

test('the job writes a security-event CSV scoped to current members', function (): void {
    Storage::fake('local');
    Mail::fake();

    [$owner, $team] = exportTeam();
    $member = exportMember($team, TeamRole::Admin);
    $outsider = User::factory()->create();

    $memberEvent = SecurityEvent::factory()->for($member)->create([
        'type' => SecurityEventType::LoggedIn,
        'ip_address' => null,
        'is_new_device' => true,
    ]);
    SecurityEvent::factory()->for($outsider)->create(['type' => SecurityEventType::LoggedIn]);

    $export = AuditExport::factory()->for($team)->security()->create([
        'requested_by' => $owner->id,
    ]);

    (new GenerateAuditExport($export->id))->handle();

    $csv = Storage::disk('local')->get($export->refresh()->path);
    expect($csv)->toContain('id,occurred_at,type,type_label,actor_name,actor_id,ip_address,user_agent,is_new_device');
    expect($csv)->toContain($memberEvent->id);
    expect($csv)->toContain('true');
    expect($csv)->not->toContain($outsider->name);
});

test('the job writes a security-event JSON document', function (): void {
    Storage::fake('local');
    Mail::fake();

    [$owner, $team] = exportTeam();
    SecurityEvent::factory()->for($owner)->create(['type' => SecurityEventType::LoggedIn]);

    $export = AuditExport::factory()->for($team)->security()->json()->create([
        'requested_by' => $owner->id,
    ]);

    (new GenerateAuditExport($export->id))->handle();

    $records = json_decode((string) Storage::disk('local')->get($export->refresh()->path), true);
    expect($records)->toHaveCount(1);
    expect($records[0]['type'])->toBe('logged_in');
});

test('a date range limits the exported records, in the requester timezone', function (): void {
    Storage::fake('local');
    Mail::fake();

    [$owner, $team] = exportTeam();
    $owner->update(['timezone' => 'America/New_York']);

    AuditActivity::factory()->forTeam($team)->ofAction(AuditAction::ChannelCreated)->create([
        'properties' => ['channel_name' => 'january'],
        'created_at' => '2026-01-15 12:00:00',
    ]);
    AuditActivity::factory()->forTeam($team)->ofAction(AuditAction::ChannelCreated)->create([
        'properties' => ['channel_name' => 'june'],
        'created_at' => '2026-06-15 12:00:00',
    ]);

    $export = AuditExport::factory()->for($team)->create([
        'requested_by' => $owner->id,
        'log_type' => AuditExportLogType::Audit,
        'range_start' => '2026-06-01',
        'range_end' => '2026-06-30',
    ]);

    (new GenerateAuditExport($export->id))->handle();

    $csv = Storage::disk('local')->get($export->refresh()->path);
    expect($csv)->toContain('june');
    expect($csv)->not->toContain('january');
});

test('an export whose requester has no timezone falls back to UTC', function (): void {
    Storage::fake('local');
    Mail::fake();

    [$owner, $team] = exportTeam();
    $owner->update(['timezone' => null]);

    AuditActivity::factory()->forTeam($team)->ofAction(AuditAction::ChannelCreated)->create([
        'properties' => ['channel_name' => 'june'],
        'created_at' => '2026-06-15 12:00:00',
    ]);

    $export = AuditExport::factory()->for($team)->create([
        'requested_by' => $owner->id,
        'log_type' => AuditExportLogType::Audit,
        'range_start' => '2026-06-01',
        'range_end' => '2026-06-30',
    ]);

    (new GenerateAuditExport($export->id))->handle();

    expect(Storage::disk('local')->get($export->refresh()->path))->toContain('june');
});

test('the job bails quietly when the export no longer exists', function (): void {
    Mail::fake();

    $export = AuditExport::factory()->create();
    $id = $export->id;
    $export->delete();

    (new GenerateAuditExport($id))->handle();

    Mail::assertNothingSent();
});

test('the failed hook marks the export failed', function (): void {
    $export = AuditExport::factory()->create();

    (new GenerateAuditExport($export->id))->failed(new RuntimeException('boom'));

    expect($export->refresh()->status)->toBe(AuditExportStatus::Failed);
});

test('an export whose requester was deleted still generates without emailing', function (): void {
    Storage::fake('local');
    Mail::fake();

    [, $team] = exportTeam();
    AuditActivity::factory()->forTeam($team)->ofAction(AuditAction::ChannelCreated)->create([
        'properties' => ['channel_name' => 'june'],
        'created_at' => '2026-06-15 12:00:00',
    ]);

    $export = AuditExport::factory()->for($team)->create([
        'requested_by' => null,
        'log_type' => AuditExportLogType::Audit,
        'range_start' => '2026-06-01',
        'range_end' => '2026-06-30',
    ]);

    (new GenerateAuditExport($export->id))->handle();

    expect($export->refresh()->status)->toBe(AuditExportStatus::Ready);
    expect(Storage::disk('local')->get($export->path))->toContain('june');
    Mail::assertNothingSent();
});

test('a failed notification does not undo a ready export', function (): void {
    Storage::fake('local');

    [$owner, $team] = exportTeam();
    $export = AuditExport::factory()->for($team)->create([
        'requested_by' => $owner->id,
        'log_type' => AuditExportLogType::Audit,
    ]);

    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down'));

    (new GenerateAuditExport($export->id))->handle();

    expect($export->refresh()->status)->toBe(AuditExportStatus::Ready);
    expect($export->path)->not->toBeNull();
});

test('csv fields that begin like a formula are neutralised', function (): void {
    Storage::fake('local');
    Mail::fake();

    [$owner, $team] = exportTeam();
    $owner->update(['name' => '=1+1']);
    AuditActivity::factory()->forTeam($team)->ofAction(AuditAction::ChannelCreated)->causedBy($owner)->create([
        'properties' => ['channel_name' => 'general'],
    ]);

    $export = AuditExport::factory()->for($team)->create([
        'requested_by' => $owner->id,
        'log_type' => AuditExportLogType::Audit,
        'format' => AuditExportFormat::Csv,
    ]);

    (new GenerateAuditExport($export->id))->handle();

    $csv = Storage::disk('local')->get($export->refresh()->path);
    expect($csv)->toContain("'=1+1");
});

// ── Downloading ──────────────────────────────────────────────────────────────

test('any current admin can download a ready export', function (): void {
    Storage::fake('local');

    [$owner, $team] = exportTeam();
    $otherAdmin = exportMember($team, TeamRole::Admin);
    $export = AuditExport::factory()->for($team)->ready()->create(['requested_by' => $owner->id]);
    Storage::disk('local')->put($export->path, 'csv-bytes');

    $this->actingAs($otherAdmin)
        ->get(route('teams.audit-exports.download', [$team, $export]))
        ->assertOk()
        ->assertDownload('audit-export.csv');
});

test('a plain member cannot download an export', function (): void {
    Storage::fake('local');

    [$owner, $team] = exportTeam();
    $member = exportMember($team, TeamRole::Member);
    $export = AuditExport::factory()->for($team)->ready()->create(['requested_by' => $owner->id]);
    Storage::disk('local')->put($export->path, 'csv-bytes');

    $this->actingAs($member)
        ->get(route('teams.audit-exports.download', [$team, $export]))
        ->assertForbidden();
});

test('a security export download re-checks the security-log policy', function (): void {
    Storage::fake('local');

    [$owner, $team] = exportTeam();
    $export = AuditExport::factory()->for($team)->security()->ready()->create(['requested_by' => $owner->id]);
    Storage::disk('local')->put($export->path, 'csv-bytes');

    $this->actingAs($owner)
        ->get(route('teams.audit-exports.download', [$team, $export]))
        ->assertOk()
        ->assertDownload('security-export.csv');
});

test('a pending export cannot be downloaded', function (): void {
    [$owner, $team] = exportTeam();
    $export = AuditExport::factory()->for($team)->create(['requested_by' => $owner->id]);

    $this->actingAs($owner)
        ->get(route('teams.audit-exports.download', [$team, $export]))
        ->assertNotFound();
});

test('an expired export cannot be downloaded', function (): void {
    Storage::fake('local');

    [$owner, $team] = exportTeam();
    $export = AuditExport::factory()->for($team)->expired()->create(['requested_by' => $owner->id]);
    Storage::disk('local')->put($export->path, 'csv-bytes');

    $this->actingAs($owner)
        ->get(route('teams.audit-exports.download', [$team, $export]))
        ->assertNotFound();
});

test('an export from another team cannot be downloaded', function (): void {
    Storage::fake('local');

    [$owner, $team] = exportTeam();
    [, $otherTeam] = exportTeam();
    $export = AuditExport::factory()->for($otherTeam)->ready()->create();
    Storage::disk('local')->put($export->path, 'csv-bytes');

    $this->actingAs($owner)
        ->get(route('teams.audit-exports.download', [$team, $export]))
        ->assertNotFound();
});

// ── DTO + model + mail ───────────────────────────────────────────────────────

test('the DTO maps a ready export', function (): void {
    $export = AuditExport::factory()->ready()->create([
        'range_start' => '2026-04-01',
        'range_end' => '2026-06-30',
    ]);

    $data = AuditExportData::fromExport($export);

    expect($data->logType)->toBe('audit');
    expect($data->format)->toBe('csv');
    expect($data->status)->toBe('ready');
    expect($data->isReady)->toBeTrue();
    expect($data->isExpired)->toBeFalse();
    expect($data->rangeStart)->toBe('2026-04-01');
    expect($data->rangeEnd)->toBe('2026-06-30');
    expect($data->requestedByName)->not->toBeNull();
    expect($data->expiresAt)->not->toBeNull();
});

test('the DTO maps a pending all-time export', function (): void {
    $data = AuditExportData::fromExport(AuditExport::factory()->create());

    expect($data->status)->toBe('pending');
    expect($data->isReady)->toBeFalse();
    expect($data->rangeStart)->toBeNull();
    expect($data->rangeEnd)->toBeNull();
    expect($data->expiresAt)->toBeNull();
});

test('the DTO tolerates a deleted requester', function (): void {
    $data = AuditExportData::fromExport(AuditExport::factory()->create(['requested_by' => null]));

    expect($data->requestedByName)->toBeNull();
});

test('a ready JSON export is built with a matching path extension', function (): void {
    $export = AuditExport::factory()->json()->ready()->create();

    expect($export->path)->toEndWith('.json');
});

test('the model reports an expired export as not ready', function (): void {
    $export = AuditExport::factory()->expired()->create();

    expect($export->isReady())->toBeTrue();
    expect($export->isExpired())->toBeTrue();
});

test('the ready-export mail renders the download link', function (): void {
    $export = AuditExport::factory()->ready()->create();

    $mail = new AuditExportReady($export);

    $mail->assertHasSubject('Your audit export is ready');
    $mail->assertSeeInHtml('Download export');
});
