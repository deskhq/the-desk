<?php

declare(strict_types=1);

namespace App\Actions\Teams;

use App\Models\AuditExport;
use App\Support\ExpirySweep;
use App\Support\ExportLifecycle;

class PurgeExpiredAuditExports
{
    /**
     * Delete audit exports whose download window has closed, removing both the
     * file on the private disk and the row.
     *
     * Ready exports carry a file that would otherwise linger on disk forever once
     * the link stops working, so the file is deleted before the row. Pending or
     * failed exports never reach `expires_at`, so only ready-then-expired ones
     * are swept here. The sweep itself is {@see ExpirySweep}'s.
     *
     * @return int the number of exports purged
     */
    public function handle(): int
    {
        return ExpirySweep::purgeExpiredExports(AuditExport::query(), ExportLifecycle::DISK);
    }
}
