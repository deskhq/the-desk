<?php

declare(strict_types=1);

namespace App\Enums;

enum ScheduledMessageStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
