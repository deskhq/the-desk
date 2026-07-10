<?php

use App\Actions\Channels\DispatchDueScheduledMessages;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
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
