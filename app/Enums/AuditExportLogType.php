<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which of the workspace's two append-only logs an export draws from. The value
 * is stored on the `audit_exports` row and picks the data source and columns the
 * export job writes.
 */
enum AuditExportLogType: string
{
    case Audit = 'audit';
    case Security = 'security';

    /**
     * Get the human-readable label shown in the log picker and export rows.
     */
    public function label(): string
    {
        return match ($this) {
            self::Audit => __('Audit log'),
            self::Security => __('Security events'),
        };
    }

    /**
     * Get the selectable log options for the request form.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
