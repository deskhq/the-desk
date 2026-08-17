<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\DataExportStatus;
use App\Enums\SecurityEventType;
use App\Events\SecurityEventOccurred;
use App\Jobs\ExportUserData;
use App\Models\DataExport;
use App\Models\User;

/**
 * Queues a fresh export of a user's personal data.
 *
 * The row is created pending and the archive is built off-request by
 * {@see ExportUserData}; requesting one is a security-relevant account action,
 * so it is recorded here rather than by whichever surface asked for it.
 */
final class RequestDataExport
{
    public function handle(User $user): DataExport
    {
        /** @var DataExport $export */
        $export = $user->dataExports()->create([
            'status' => DataExportStatus::Pending,
        ]);

        dispatch(new ExportUserData($export->id));

        event(new SecurityEventOccurred($user, SecurityEventType::DataExportRequested));

        return $export;
    }
}
