<?php

declare(strict_types=1);

namespace App\Enums;

enum MessageReminderStatus: string
{
    case Pending = 'pending';
    case Fired = 'fired';
}
