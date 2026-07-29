<?php

use App\Actions\Users\PurgeExpiredDataExports;
use App\Enums\DataExportStatus;
use App\Models\DataExport;
use Illuminate\Support\Facades\Storage;

test('it deletes expired archives and rows and keeps live ones', function (): void {
    Storage::fake('local');

    $expired = DataExport::factory()->expired()->create();
    Storage::disk('local')->put($expired->path, 'zip-bytes');

    $live = DataExport::factory()->ready()->create();
    Storage::disk('local')->put($live->path, 'zip-bytes');

    $purged = app(PurgeExpiredDataExports::class)->handle();

    expect($purged)->toBe(1);
    Storage::disk('local')->assertMissing($expired->path);
    Storage::disk('local')->assertExists($live->path);
    $this->assertDatabaseMissing('data_exports', ['id' => $expired->id]);
    $this->assertDatabaseHas('data_exports', ['id' => $live->id]);
});

test('a pending export with no expiry is never touched', function (): void {
    Storage::fake('local');

    $pending = DataExport::factory()->create();

    $purged = app(PurgeExpiredDataExports::class)->handle();

    expect($purged)->toBe(0);
    $this->assertDatabaseHas('data_exports', ['id' => $pending->id]);
});

test('it deletes an expired export that never produced an archive', function (): void {
    Storage::fake('local');

    $export = DataExport::factory()->create([
        'status' => DataExportStatus::Failed,
        'path' => null,
        'expires_at' => now()->subDay(),
    ]);

    $purged = app(PurgeExpiredDataExports::class)->handle();

    expect($purged)->toBe(1);
    $this->assertDatabaseMissing('data_exports', ['id' => $export->id]);
});
