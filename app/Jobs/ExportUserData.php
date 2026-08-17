<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DataExportStatus;
use App\Mail\DataExportReady;
use App\Models\DataExport;
use App\Models\Membership;
use App\Models\Message;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\ExportLifecycle;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;
use ZipArchive;

final class ExportUserData implements ShouldQueue
{
    use Queueable;

    /**
     * The directory on the private disk archives live under.
     */
    private const string DIRECTORY = 'exports';

    public function __construct(private string $dataExportId) {}

    /**
     * Assemble the user's personal data into a zip of JSON files on the private
     * disk, then mark the export ready and email them the download link.
     *
     * Everything but the assembling is {@see ExportLifecycle}'s, including the
     * bail when the export is gone (the account may have been deleted since the
     * job was queued).
     */
    public function handle(): void
    {
        $this->lifecycle()->generate(
            write: function (DataExport $export, Filesystem $disk): array {
                $path = self::DIRECTORY.'/'.$export->id.'.zip';
                $disk->makeDirectory(self::DIRECTORY);

                $zip = new ZipArchive;
                $zip->open($disk->path($path), ZipArchive::CREATE | ZipArchive::OVERWRITE);

                foreach ($this->archiveContents($export->user) as $filename => $contents) {
                    $zip->addFromString($filename, (string) json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }

                $zip->close();

                return ['path' => $path, 'size_bytes' => $disk->size($path)];
            },
            recipient: fn (DataExport $export): User => $export->user,
            notice: fn (DataExport $export): Mailable => new DataExportReady($export),
        );
    }

    /**
     * Mark the export failed so the panel can offer a retry, discarding any
     * partial archive metadata so a failed export is never treated as ready.
     */
    public function failed(Throwable $exception): void
    {
        $this->lifecycle()->fail();
    }

    /**
     * The shared export lifecycle this job adapts into, eager-loading the user
     * the archive and the ready notice read.
     *
     * @return ExportLifecycle<DataExport>
     */
    private function lifecycle(): ExportLifecycle
    {
        return new ExportLifecycle(
            query: DataExport::with('user'),
            exportId: $this->dataExportId,
            readyStatus: DataExportStatus::Ready,
            failedStatus: DataExportStatus::Failed,
            fileAttributes: ['path', 'size_bytes'],
        );
    }

    /**
     * Build the named JSON documents that make up the archive.
     *
     * @return array<string, mixed>
     */
    private function archiveContents(User $user): array
    {
        return [
            'profile.json' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'pronouns' => $user->pronouns,
                'title' => $user->title,
                'phone' => $user->phone,
                'timezone' => $user->timezone,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'teams.json' => $user->teamMemberships()->with('team')->get()->map(fn (Membership $membership): array => [
                'id' => $membership->team->id,
                'name' => $membership->team->name,
                'slug' => $membership->team->slug,
                'is_personal' => $membership->team->is_personal,
                'role' => $membership->role->value,
            ])->all(),
            'messages.json' => Message::withTrashed()
                ->where('user_id', $user->id)
                ->get()
                ->map(fn (Message $message): array => [
                    'id' => $message->id,
                    'channel_id' => $message->channel_id,
                    'body' => $message->body,
                    'created_at' => $message->created_at?->toIso8601String(),
                    'deleted_at' => $message->deleted_at?->toIso8601String(),
                ])->all(),
            'security-events.json' => $user->securityEvents()->get()->map(fn (SecurityEvent $event): array => [
                'type' => $event->type->value,
                'ip_address' => $event->ip_address,
                'user_agent' => $event->user_agent,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
