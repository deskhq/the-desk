<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The lifecycle every asynchronous file export runs: resolve the row or bail,
 * write the file to the private disk, mark the row ready, tell whoever asked
 * for it, and — when the job fails — mark it failed with nothing left behind
 * that reads as a downloadable file.
 *
 * The audit-log export and the personal-data export are both adapters over
 * this; they supply only what genuinely differs (which rows, which statuses,
 * how the file is written, who hears about it). Two questions they used to
 * answer differently are answered once, here:
 *
 * - **A failed notification never undoes a written export.** The file is the
 *   deliverable and the mail is a courtesy, so a throwing mailer is reported
 *   and swallowed rather than tripping the job's `failed()` handler. It is
 *   skipped outright when the requester's account was deleted between request
 *   and generation.
 * - **A failed export discards its archive metadata.** Status alone is not
 *   enough: a path left on a failed row outlives the job and still reads as a
 *   file you can download.
 *
 * @template TExport of Model
 */
class ExportLifecycle
{
    /**
     * The private disk every export file is written to.
     */
    public const string DISK = 'local';

    /**
     * How many days a built file stays downloadable before it is purged.
     */
    public const int RETENTION_DAYS = 7;

    /**
     * @param  Builder<TExport>  $query  resolves the export row, eager-loading whatever the write and the notice read
     * @param  string  $exportId  the row's key; jobs hold an id, not a model, so a deleted row is a quiet no-op
     * @param  BackedEnum  $readyStatus  the status a built export carries
     * @param  BackedEnum  $failedStatus  the status an export that could not be built carries
     * @param  array<int, string>  $fileAttributes  the columns describing the written file, nulled again on failure
     */
    public function __construct(
        private readonly Builder $query,
        private readonly string $exportId,
        private readonly BackedEnum $readyStatus,
        private readonly BackedEnum $failedStatus,
        private readonly array $fileAttributes = ['path'],
    ) {}

    /**
     * Write the file, mark the export ready, and send the ready notice.
     *
     * Bails quietly when the row is gone: the workspace or the account may have
     * been deleted since the job was queued.
     *
     * @param  Closure(TExport, Filesystem): array<string, mixed>  $write  writes the file, returning the columns that describe it
     * @param  Closure(TExport): ?User  $recipient  who to tell, or null once that account is gone
     * @param  Closure(TExport): Mailable  $notice  the mail announcing the finished file
     */
    public function generate(Closure $write, Closure $recipient, Closure $notice): void
    {
        $export = $this->query->clone()->find($this->exportId);

        if ($export === null) {
            return;
        }

        $export->update([
            ...$write($export, Storage::disk(self::DISK)),
            'status' => $this->readyStatus,
            'expires_at' => now()->addDays(self::RETENTION_DAYS),
        ]);

        $this->announce($export, $recipient($export), $notice);
    }

    /**
     * Mark the export failed so its panel can offer a retry, discarding the
     * archive metadata so a failed export is never treated as ready.
     */
    public function fail(): void
    {
        $this->query->clone()->whereKey($this->exportId)->update([
            ...array_fill_keys($this->fileAttributes, null),
            'status' => $this->failedStatus,
            'expires_at' => null,
        ]);
    }

    /**
     * Mail the ready notice, absorbing both reasons it might not arrive: there
     * is no longer anyone to tell, or the mailer itself threw.
     *
     * @param  TExport  $export
     * @param  Closure(TExport): Mailable  $notice
     */
    private function announce(Model $export, ?User $recipient, Closure $notice): void
    {
        if (! $recipient instanceof User) {
            return;
        }

        try {
            Mail::to($recipient)->send($notice($export));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
