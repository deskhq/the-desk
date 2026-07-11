<?php

namespace App\Enums;

enum DataExportStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';

    /**
     * Get the human-readable label shown on the Data & privacy panel.
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
