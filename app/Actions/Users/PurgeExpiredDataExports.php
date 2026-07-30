<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\DataExport;
use App\Support\ExpirySweep;
use App\Support\ExportLifecycle;

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
     * security events, so no evidence is lost. The sweep itself is
     * {@see ExpirySweep}'s.
     *
     * @return int the number of exports purged
     */
    public function handle(): int
    {
        return ExpirySweep::purgeExpiredExports(DataExport::query(), ExportLifecycle::DISK);
    }
}
