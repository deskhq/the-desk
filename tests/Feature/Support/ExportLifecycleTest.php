<?php

declare(strict_types=1);

use App\Enums\DataExportStatus;
use App\Mail\DataExportReady;
use App\Models\DataExport;
use App\Models\User;
use App\Support\ExportLifecycle;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Build the lifecycle over a data export, standing in for either job: the two
 * differ only in the arguments below.
 *
 * @return ExportLifecycle<DataExport>
 */
function lifecycleFor(string $exportId): ExportLifecycle
{
    return new ExportLifecycle(
        query: DataExport::with('user'),
        exportId: $exportId,
        readyStatus: DataExportStatus::Ready,
        failedStatus: DataExportStatus::Failed,
        fileAttributes: ['path', 'size_bytes'],
    );
}

/**
 * Run the lifecycle's generate step, writing a one-line file and telling the
 * export's own user unless the caller says otherwise.
 */
function generateThrough(ExportLifecycle $lifecycle, ?Closure $recipient = null): void
{
    $lifecycle->generate(
        write: function (DataExport $export, Filesystem $disk): array {
            $disk->put('exports/'.$export->id.'.txt', 'archive-bytes');

            return ['path' => 'exports/'.$export->id.'.txt', 'size_bytes' => 13];
        },
        recipient: $recipient ?? fn (DataExport $export): ?User => $export->user,
        notice: fn (DataExport $export): Mailable => new DataExportReady($export),
    );
}

test('it writes the file, marks the export ready for the retention window, and sends the notice', function (): void {
    Storage::fake('local');
    Mail::fake();

    $user = User::factory()->create();
    $export = DataExport::factory()->for($user)->create();

    generateThrough(lifecycleFor($export->id));

    $export->refresh();

    expect($export->status)->toBe(DataExportStatus::Ready);
    expect($export->path)->toBe('exports/'.$export->id.'.txt');
    expect($export->size_bytes)->toBe(13);
    expect($export->expires_at?->toDateTimeString())
        ->toBe(now()->addDays(ExportLifecycle::RETENTION_DAYS)->toDateTimeString());

    Storage::disk(ExportLifecycle::DISK)->assertExists($export->path);
    Mail::assertSent(DataExportReady::class, fn (DataExportReady $mail): bool => $mail->hasTo($user->email));
});

test('it skips the notice when there is no one left to tell', function (): void {
    Storage::fake('local');
    Mail::fake();

    $export = DataExport::factory()->create();

    generateThrough(lifecycleFor($export->id), fn (DataExport $export): ?User => null);

    expect($export->refresh()->status)->toBe(DataExportStatus::Ready);
    Mail::assertNothingSent();
});

test('it bails quietly when the export is gone', function (): void {
    Mail::fake();

    $export = DataExport::factory()->create();
    $id = $export->id;
    $export->delete();

    generateThrough(lifecycleFor($id));

    Mail::assertNothingSent();
});

test('failing nulls every column that described the written file', function (): void {
    $export = DataExport::factory()->ready()->create();

    lifecycleFor($export->id)->fail();

    $export->refresh();

    expect($export->status)->toBe(DataExportStatus::Failed);
    expect($export->path)->toBeNull();
    expect($export->size_bytes)->toBeNull();
    expect($export->expires_at)->toBeNull();
});
