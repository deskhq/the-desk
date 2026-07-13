<?php

declare(strict_types=1);

use App\Actions\Channels\DispatchDueMessageReminders;
use App\Actions\Channels\DispatchDueScheduledMessages;
use App\Actions\Channels\PurgeExpiredAttachments;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

Schedule::call(fn (DispatchDueScheduledMessages $dispatch) => $dispatch->handle())
    ->name('deliver-due-scheduled-messages')
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Deliver due scheduled messages');

Schedule::call(fn (DispatchDueMessageReminders $dispatch) => $dispatch->handle())
    ->name('fire-due-message-reminders')
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Fire due message reminders');

Schedule::call(fn (PurgeExpiredAttachments $purge): int => $purge->handle())
    ->name('purge-expired-pending-attachments')
    ->hourly()
    ->withoutOverlapping()
    ->description('Purge pending attachments never claimed by a message');
