<?php

declare(strict_types=1);

use App\Actions\Channels\DispatchDueMessageReminders;
use App\Actions\Channels\DispatchDueScheduledMessages;
use App\Actions\Channels\PurgeExpiredAttachments;
use App\Actions\Channels\PurgeExpiredChannels;
use App\Actions\Images\PurgeCachedProxyImages;
use App\Actions\Integrations\PruneWebhookDeliveries;
use App\Actions\Teams\PurgeExpiredAuditExports;
use App\Actions\Users\BroadcastDndScheduleEdges;
use App\Actions\Users\ClearExpiredUserStatuses;
use App\Actions\Users\ClearLapsedDndPauses;
use App\Actions\Users\ClearLapsedDndScheduleSnoozes;
use App\Actions\Users\PruneSecurityEvents;
use App\Actions\Users\PurgeExpiredDataExports;
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

Schedule::call(fn (PurgeExpiredChannels $purge): int => $purge->handle())
    ->name('purge-expired-deleted-channels')
    ->daily()
    ->withoutOverlapping()
    ->description('Purge deleted channels past their restore window');

Schedule::call(fn (PurgeExpiredAuditExports $purge): int => $purge->handle())
    ->name('purge-expired-audit-exports')
    ->daily()
    ->withoutOverlapping()
    ->description('Purge expired audit-log exports (files and rows)');

Schedule::call(fn (PurgeExpiredDataExports $purge): int => $purge->handle())
    ->name('purge-expired-data-exports')
    ->daily()
    ->withoutOverlapping()
    ->description('Purge expired data-export archives (files and rows)');

Schedule::call(fn (PruneWebhookDeliveries $prune): int => $prune->handle())
    ->name('prune-webhook-deliveries')
    ->daily()
    ->withoutOverlapping()
    ->description('Prune webhook delivery attempts past the retention window');

Schedule::call(fn (PruneSecurityEvents $prune): int => $prune->handle())
    ->name('prune-security-events')
    ->daily()
    ->withoutOverlapping()
    ->description('Prune security events past the retention window');

// Spatie's clean command reads `activitylog.clean_after_days` itself, so the
// window lives in config rather than in a --days flag here. --force is required:
// the command is confirmable, and unattended in production it would otherwise
// cancel itself at the prompt instead of pruning.
Schedule::command('activitylog:clean --force')
    ->daily()
    ->withoutOverlapping()
    ->when(fn (): bool => (int) config('activitylog.clean_after_days') >= 1)
    ->description('Prune workspace audit-log entries past the retention window');

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
