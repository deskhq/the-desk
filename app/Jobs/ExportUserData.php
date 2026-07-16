<?php

namespace App\Jobs;

use App\Enums\DataExportStatus;
use App\Mail\DataExportReady;
use App\Models\DataExport;
use App\Models\Membership;
use App\Models\Message;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

class ExportUserData implements ShouldQueue
{
    use Queueable;

    /**
     * The private disk the archive is written to.
     */
    public const string DISK = 'local';

    /**
     * How many days the built archive stays downloadable before it is purged.
     */
    private const int RETENTION_DAYS = 7;

    public function __construct(private string $dataExportId) {}

    /**
     * Assemble the user's personal data into a zip of JSON files on the private
     * disk, then mark the export ready and email them the download link.
     *
     * Re-fetches by id and bails quietly when the export is gone (the account may
     * have been deleted since the job was queued).
     */
    public function handle(): void
    {
        $export = DataExport::with('user')->find($this->dataExportId);

        if ($export === null) {
            return;
        }

        $path = 'exports/'.$export->id.'.zip';
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory('exports');

        $zip = new ZipArchive;
        $zip->open($disk->path($path), ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($this->archiveContents($export->user) as $filename => $contents) {
            $zip->addFromString($filename, (string) json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $zip->close();

        $export->update([
            'status' => DataExportStatus::Ready,
            'path' => $path,
            'size_bytes' => $disk->size($path),
            'expires_at' => now()->addDays(self::RETENTION_DAYS),
        ]);

        Mail::to($export->user)->send(new DataExportReady($export));
    }

    /**
     * Mark the export failed so the panel can offer a retry, discarding any
     * partial archive metadata so a failed export is never treated as ready.
     */
    public function failed(Throwable $exception): void
    {
        DataExport::whereKey($this->dataExportId)->update([
            'status' => DataExportStatus::Failed,
            'path' => null,
            'size_bytes' => null,
            'expires_at' => null,
        ]);
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
