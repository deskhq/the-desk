<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\DataExport;
use App\Support\PersistedTimestamp;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The viewer's most recent personal-data export, as the profile settings page's
 * "Data & privacy" section renders it.
 *
 * `isReady` is true only while the archive is both built and still inside its
 * download window, so one flag answers "can I download this now"; `sizeBytes` is
 * null until the build captures it (an older or unbuilt export has none).
 */
#[TypeScript]
final class DataExportData extends Data
{
    public function __construct(
        public string $id,
        public string $status,
        public string $statusLabel,
        public bool $isReady,
        public string $requestedAt,
        public ?string $expiresAt,
        public ?int $sizeBytes,
    ) {}

    /**
     * Build the DTO from a data export record.
     */
    public static function fromExport(DataExport $export): self
    {
        return new self(
            id: $export->id,
            status: $export->status->value,
            statusLabel: $export->status->label(),
            isReady: $export->isReady() && ! $export->isExpired(),
            requestedAt: PersistedTimestamp::of($export->created_at)->toIso8601String(),
            expiresAt: $export->expires_at?->toIso8601String(),
            sizeBytes: $export->size_bytes,
        );
    }
}
