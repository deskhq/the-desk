<?php

declare(strict_types=1);

use App\Actions\Channels\DispatchDueMessageReminders;
use App\Actions\Channels\DispatchDueScheduledMessages;
use App\Actions\Channels\PurgeExpiredAttachments;
use App\Actions\Images\PurgeCachedProxyImages;
use App\Actions\Teams\PurgeExpiredAuditExports;
use App\Actions\Users\BroadcastDndScheduleEdges;
use App\Actions\Users\ClearExpiredUserStatuses;
use App\Actions\Users\ClearLapsedDndPauses;
use App\Actions\Users\ClearLapsedDndScheduleSnoozes;
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

Schedule::call(fn (ClearExpiredUserStatuses $clear): int => $clear->handle())
    ->name('clear-expired-user-statuses')
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Clear lapsed custom statuses');

Schedule::call(fn (ClearLapsedDndPauses $clear): int => $clear->handle())
    ->name('clear-lapsed-dnd-pauses')
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Clear lapsed do-not-disturb pauses');

Schedule::call(fn (ClearLapsedDndScheduleSnoozes $clear): int => $clear->handle())
    ->name('clear-lapsed-dnd-schedule-snoozes')
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Clear lapsed quiet-hours snoozes');

Schedule::call(fn (BroadcastDndScheduleEdges $broadcast): int => $broadcast->handle())
    ->name('broadcast-dnd-schedule-edges')
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Broadcast quiet-hours windows opening or closing');

Schedule::call(fn (PurgeExpiredAttachments $purge): int => $purge->handle())
    ->name('purge-expired-pending-attachments')
    ->hourly()
    ->withoutOverlapping()
    ->description('Purge pending attachments never claimed by a message');

Schedule::call(fn (PurgeExpiredAuditExports $purge): int => $purge->handle())
    ->name('purge-expired-audit-exports')
    ->daily()
    ->withoutOverlapping()
    ->description('Purge expired audit-log exports (files and rows)');

Schedule::call(fn (PurgeCachedProxyImages $purge): int => $purge->handle())
    ->name('purge-cached-proxy-images')
    ->daily()
    ->withoutOverlapping()
    ->description('Purge proxied remote images past their cache TTL');

Schedule::command('updates:check')
    ->daily()
    ->withoutOverlapping()
    ->description('Check GitHub for a newer stable release');

// The public demo heals hourly: the idempotent seeder wipes and rebuilds the
// shared "Northwind Labs" workspace, undoing whatever a visitor changed within
// the guard rails. Gated on DEMO_MODE, so it never runs on a real deployment.
Schedule::command('demo:seed')
    ->hourly()
    ->withoutOverlapping()
    ->when(fn (): bool => (bool) config('demo.mode'))
    ->description('Reset the public demo workspace');
