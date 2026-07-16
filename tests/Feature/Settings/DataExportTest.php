<?php

use App\Data\DataExportData;
use App\Enums\DataExportStatus;
use App\Enums\SecurityEventType;
use App\Jobs\ExportUserData;
use App\Mail\DataExportReady;
use App\Models\Channel;
use App\Models\DataExport;
use App\Models\Message;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('requesting an export queues the job and records a pending export', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->post(route('data-export.store'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    Queue::assertPushed(ExportUserData::class);

    $export = $user->dataExports()->first();

    expect($export)->not->toBeNull();
    expect($export->status)->toBe(DataExportStatus::Pending);
    expect(SecurityEvent::query()->where('user_id', $user->id)->where('type', SecurityEventType::DataExportRequested)->count())->toBe(1);
});

test('the export job builds a downloadable archive and emails the user', function (): void {
    Storage::fake('local');
    Mail::fake();

    $user = User::factory()->create();
    $channel = Channel::factory()->create();
    Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Hello from the archive',
    ]);
    SecurityEvent::factory()->for($user)->create();

    $export = DataExport::factory()->for($user)->create();

    (new ExportUserData($export->id))->handle();

    $export->refresh();

    expect($export->status)->toBe(DataExportStatus::Ready);
    expect($export->path)->not->toBeNull();
    expect($export->expires_at)->not->toBeNull();
    Storage::disk('local')->assertExists($export->path);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));
    $profile = json_decode((string) $zip->getFromName('profile.json'), true);
    $messages = json_decode((string) $zip->getFromName('messages.json'), true);
    $zip->close();

    expect($profile['email'])->toBe($user->email);
    expect($messages)->toHaveCount(1);
    expect($messages[0]['body'])->toBe('Hello from the archive');

    Mail::assertSent(DataExportReady::class, fn (DataExportReady $mail): bool => $mail->hasTo($user->email));
});

test('the export job records the archive byte size', function (): void {
    Storage::fake('local');
    Mail::fake();

    $user = User::factory()->create();
    $export = DataExport::factory()->for($user)->create();

    (new ExportUserData($export->id))->handle();

    $export->refresh();

    expect($export->size_bytes)->toBe(strlen((string) Storage::disk('local')->get($export->path)));
    expect($export->size_bytes)->toBeGreaterThan(0);
});

test('the job bails quietly when the export no longer exists', function (): void {
    Mail::fake();

    $export = DataExport::factory()->create();
    $id = $export->id;
    $export->delete();

    (new ExportUserData($id))->handle();

    Mail::assertNothingSent();
});

test('the failed hook marks the export failed', function (): void {
    $export = DataExport::factory()->create();

    (new ExportUserData($export->id))->failed(new RuntimeException('boom'));

    expect($export->refresh()->status)->toBe(DataExportStatus::Failed);
});

test('the owner can download a ready export', function (): void {
    Storage::fake('local');

    $user = User::factory()->create();
    $export = DataExport::factory()->for($user)->ready()->create();
    Storage::disk('local')->put($export->path, 'zip-bytes');

    $this->actingAs($user)
        ->get(route('data-export.download', $export))
        ->assertOk()
        ->assertDownload('data-export.zip');

    expect(SecurityEvent::query()->where('user_id', $user->id)->where('type', SecurityEventType::DataExportDownloaded)->count())->toBe(1);
});

test('another user cannot download an export', function (): void {
    Storage::fake('local');

    $export = DataExport::factory()->ready()->create();
    Storage::disk('local')->put($export->path, 'zip-bytes');

    $this->actingAs(User::factory()->create())
        ->get(route('data-export.download', $export))
        ->assertForbidden();
});

test('a pending export cannot be downloaded', function (): void {
    $user = User::factory()->create();
    $export = DataExport::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('data-export.download', $export))
        ->assertNotFound();
});

test('an expired export cannot be downloaded', function (): void {
    Storage::fake('local');

    $user = User::factory()->create();
    $export = DataExport::factory()->for($user)->expired()->create();
    Storage::disk('local')->put($export->path, 'zip-bytes');

    $this->actingAs($user)
        ->get(route('data-export.download', $export))
        ->assertNotFound();
});

test('the data & privacy page carries the latest export', function (): void {
    $user = User::factory()->create();
    DataExport::factory()->for($user)->ready()->create(['size_bytes' => 134_217_728]);

    $this->actingAs($user)
        ->get(route('data-export.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/DataPrivacy')
            ->where('dataExport.status', 'ready')
            ->where('dataExport.isReady', true)
            ->where('dataExport.sizeBytes', 134_217_728));
});

test('the data & privacy page carries a null export when none has been requested', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('data-export.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/DataPrivacy')
            ->where('dataExport', null));
});

test('the DTO maps a ready export', function (): void {
    $export = DataExport::factory()->ready()->create();

    $data = DataExportData::fromExport($export);

    expect($data->status)->toBe('ready');
    expect($data->statusLabel)->toBe('Ready to download');
    expect($data->isReady)->toBeTrue();
    expect($data->expiresAt)->not->toBeNull();
    expect($data->requestedAt)->not->toBeNull();
});

test('the DTO exposes the archive size when captured', function (): void {
    $export = DataExport::factory()->ready()->create(['size_bytes' => 134_217_728]);

    $data = DataExportData::fromExport($export);

    expect($data->sizeBytes)->toBe(134_217_728);
});

test('the DTO leaves the size null when it was never captured', function (): void {
    $export = DataExport::factory()->create();

    $data = DataExportData::fromExport($export);

    expect($data->sizeBytes)->toBeNull();
});

test('the DTO maps a pending export', function (): void {
    $export = DataExport::factory()->create();

    $data = DataExportData::fromExport($export);

    expect($data->status)->toBe('pending');
    expect($data->statusLabel)->toBe('Preparing');
    expect($data->isReady)->toBeFalse();
    expect($data->expiresAt)->toBeNull();
});

test('the ready-export mail renders the download link', function (): void {
    $export = DataExport::factory()->ready()->create();

    $mail = new DataExportReady($export);

    $mail->assertHasSubject('Your data export is ready');
    $mail->assertSeeInHtml('Download your data');
});
