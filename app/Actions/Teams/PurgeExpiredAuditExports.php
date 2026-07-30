<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Support\ExportLifecycle;
use App\Models\AuditExport;
use Illuminate\Support\Facades\Storage;

class PurgeExpiredAuditExports
{
    /**
     * Delete audit exports whose download window has closed, removing both the
     * file on the private disk and the row.
     *
     * Ready exports carry a file that would otherwise linger on disk forever once
     * the link stops working, so the file is deleted before the row. Pending or
     * failed exports never reach `expires_at`, so only ready-then-expired ones
     * are swept here.
     *
     * @return int the number of exports purged
     */
    public function handle(): int
    {
        $disk = Storage::disk(ExportLifecycle::DISK);

        $purged = 0;

        AuditExport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->cursor()
            ->each(function (AuditExport $export) use ($disk, &$purged): void {
                if ($export->path !== null) {
                    $disk->delete($export->path);
                }

                $export->delete();

                $purged++;
            });

        return $purged;
    }
}
