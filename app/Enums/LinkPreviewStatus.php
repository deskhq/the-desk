<?php

declare(strict_types=1);

namespace App\Enums;

enum LinkPreviewStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
