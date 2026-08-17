<?php

declare(strict_types=1);

use App\Actions\Teams\PurgeExpiredAuditExports;
use App\Enums\AuditExportStatus;
use App\Models\AuditExport;
use Illuminate\Support\Facades\Storage;

test('it deletes expired export files and rows and keeps live ones', function (): void {
    Storage::fake('local');

    $expired = AuditExport::factory()->expired()->create();
    Storage::disk('local')->put($expired->path, 'csv-bytes');

    $live = AuditExport::factory()->ready()->create();
    Storage::disk('local')->put($live->path, 'csv-bytes');

    $purged = app(PurgeExpiredAuditExports::class)->handle();

    expect($purged)->toBe(1);
    Storage::disk('local')->assertMissing($expired->path);
    Storage::disk('local')->assertExists($live->path);
    expect(AuditExport::query()->whereKey($expired->id)->exists())->toBeFalse();
    expect(AuditExport::query()->whereKey($live->id)->exists())->toBeTrue();
});

test('it deletes an expired export that never produced a file', function (): void {
    Storage::fake('local');

    $export = AuditExport::factory()->create([
        'status' => AuditExportStatus::Failed,
        'path' => null,
        'expires_at' => now()->subDay(),
    ]);

    $purged = app(PurgeExpiredAuditExports::class)->handle();

    expect($purged)->toBe(1);
    expect(AuditExport::query()->whereKey($export->id)->exists())->toBeFalse();
});
