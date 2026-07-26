<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Jobs\ExportUserData;
use App\Models\DataExport;
use Illuminate\Support\Facades\Storage;

class PurgeExpiredDataExports
{
    /**
     * Delete personal-data archives whose download window has closed, removing
     * both the file on the private disk and the row.
     *
     * The archive is a full copy of someone's profile, teams, messages, and
     * security events, so leaving it on disk after the link stops working keeps
     * the most sensitive object the app produces around forever. The row is
     * dropped with it: an expired export already reads as "no export ready" in
     * the Data & privacy panel, and the request and download are recorded as
     * security events, so no evidence is lost.
     *
     * Pending and failed exports carry no `expires_at`, so only ready-then-expired
     * ones are swept here; the file guard covers the row that never produced one.
     *
     * @return int the number of exports purged
     */
    public function handle(): int
    {
        $disk = Storage::disk(ExportUserData::DISK);

        $purged = 0;

        DataExport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->cursor()
            ->each(function (DataExport $export) use ($disk, &$purged): void {
                if ($export->path !== null) {
                    $disk->delete($export->path);
                }

                $export->delete();

                $purged++;
            });

        return $purged;
    }
}
