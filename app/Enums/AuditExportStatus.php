<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditExportStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';

    /**
     * Get the human-readable label shown on the exports page.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Preparing'),
            self::Ready => __('Ready to download'),
            self::Failed => __('Failed'),
        };
    }
}
