<?php

namespace App\Enums;

enum MessageReminderStatus: string
{
    case Pending = 'pending';
    case Fired = 'fired';
}
