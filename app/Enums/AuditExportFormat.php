<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The file format an export is written in. One export is one log in one format,
 * so this picks the writer (native CSV via `fputcsv`, or a `json_encode`d
 * document) and the download's file extension.
 */
enum AuditExportFormat: string
{
    case Csv = 'csv';
    case Json = 'json';

    /**
     * Get the human-readable label shown in the format picker and export rows.
     */
    public function label(): string
    {
        return match ($this) {
            self::Csv => __('CSV'),
            self::Json => __('JSON'),
        };
    }

    /**
     * Get the file extension the export is written with.
     */
    public function extension(): string
    {
        return $this->value;
    }

    /**
     * Get the selectable format options for the request form.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $format): array => ['value' => $format->value, 'label' => $format->label()],
            self::cases(),
        );
    }
}
