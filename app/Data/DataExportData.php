<?php

namespace App\Data;

use App\Models\DataExport;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DataExportData extends Data
{
    public function __construct(
        public string $id,
        public string $status,
        public string $statusLabel,
        public bool $isReady,
        public string $requestedAt,
        public ?string $expiresAt,
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
            requestedAt: $export->created_at->toIso8601String(),
            expiresAt: $export->expires_at?->toIso8601String(),
        );
    }
}
