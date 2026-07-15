<?php

namespace App\Data;

use App\Models\AuditExport;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class AuditExportData extends Data
{
    public function __construct(
        public string $id,
        public string $logType,
        public string $logTypeLabel,
        public string $format,
        public string $formatLabel,
        public string $status,
        public string $statusLabel,
        public bool $isReady,
        public bool $isExpired,
        public ?string $rangeStart,
        public ?string $rangeEnd,
        public ?string $requestedByName,
        public string $requestedAt,
        public ?string $expiresAt,
    ) {}

    /**
     * Build the DTO from an audit-export record.
     */
    public static function fromExport(AuditExport $export): self
    {
        return new self(
            id: $export->id,
            logType: $export->log_type->value,
            logTypeLabel: $export->log_type->label(),
            format: $export->format->value,
            formatLabel: $export->format->label(),
            status: $export->status->value,
            statusLabel: $export->status->label(),
            isReady: $export->isReady() && ! $export->isExpired(),
            isExpired: $export->isExpired(),
            rangeStart: $export->range_start?->toDateString(),
            rangeEnd: $export->range_end?->toDateString(),
            requestedByName: $export->requester?->name,
            requestedAt: $export->created_at->toIso8601String(),
            expiresAt: $export->expires_at?->toIso8601String(),
        );
    }
}
