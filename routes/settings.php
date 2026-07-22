<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\AboutController;
use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\AvatarController;
use App\Http\Controllers\Settings\DataExportController;
use App\Http\Controllers\Settings\DndController;
use App\Http\Controllers\Settings\DndScheduleController;
use App\Http\Controllers\Settings\DndScheduleSnoozeController;
use App\Http\Controllers\Settings\LocaleController;
use App\Http\Controllers\Settings\NotificationController;
use App\Http\Controllers\Settings\PersonalAccessTokenController;
use App\Http\Controllers\Settings\PresenceController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\ReadReceiptsController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SessionController;
use App\Http\Controllers\Settings\SidebarPositionController;
use App\Http\Controllers\Settings\StatusController;
use App\Http\Controllers\Settings\TimezoneController;
use App\Http\Controllers\Teams\AnalyticsController;
use App\Http\Controllers\Teams\AuditController;
use App\Http\Controllers\Teams\AuditExportController;
use App\Http\Controllers\Teams\CustomEmojiController;
use App\Http\Controllers\Teams\Integrations\BotChannelController;
use App\Http\Controllers\Teams\Integrations\BotController;
use App\Http\Controllers\Teams\Integrations\BotTokenController;
use App\Http\Controllers\Teams\Integrations\IncomingWebhookController;
use App\Http\Controllers\Teams\Integrations\IntegrationsController;
use App\Http\Controllers\Teams\Integrations\WebhookSubscriptionController;
use App\Http\Controllers\Teams\SecurityLogController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Teams\TeamMemberController;
use App\Http\Controllers\Teams\UserGroupController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::post('settings/avatar', [AvatarController::class, 'store'])->name('avatar.store');
    Route::delete('settings/avatar', [AvatarController::class, 'destroy'])->name('avatar.destroy');

    // The custom status is set from the user menu's presence section, which is
    // reachable from every workspace surface — so it sits beside the avatar in
    // the plain `auth` group rather than behind the verified-email gate.
    Route::put('settings/status', [StatusController::class, 'update'])->name('status.update');
    Route::delete('settings/status', [StatusController::class, 'destroy'])->name('status.destroy');

    // The manual away toggle sits beside the status in the same presence menu,
    // and is reachable from every workspace surface for the same reason.
    Route::put('settings/presence', [PresenceController::class, 'update'])->name('presence.update');

    // Do-not-disturb rides the same presence menu: the pause is set and ended
    // from it, and the recurring quiet-hours schedule from its dialog.
    Route::put('settings/dnd', [DndController::class, 'update'])->name('dnd.update');
    Route::delete('settings/dnd', [DndController::class, 'destroy'])->name('dnd.destroy');
    Route::put('settings/dnd-schedule', [DndScheduleController::class, 'update'])->name('dnd-schedule.update');
    Route::put('settings/dnd-schedule/snooze', [DndScheduleSnoozeController::class, 'update'])->name('dnd-schedule.snooze');

    Route::patch('settings/timezone', [TimezoneController::class, 'update'])->name('timezone.update');

    Route::get('settings/about', [AboutController::class, 'edit'])->name('about.edit');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/data-export', [DataExportController::class, 'edit'])->name('data-export.edit');
    Route::post('settings/data-export', [DataExportController::class, 'store'])->name('data-export.store');
    Route::get('settings/data-export/{dataExport}/download', [DataExportController::class, 'download'])->name('data-export.download');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::delete('settings/sessions', [SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
    Route::delete('settings/sessions/{session}', [SessionController::class, 'destroy'])->name('sessions.destroy');

    Route::get('settings/appearance', [AppearanceController::class, 'edit'])->name('appearance.edit');

    // Notification preferences now live on the combined appearance page; the old
    // route is kept as a redirect so existing deep links keep resolving.
    Route::get('settings/notifications', fn () => to_route('appearance.edit'))->name('notifications.edit');
    Route::patch('settings/notifications', [NotificationController::class, 'update'])->name('notifications.update');

    Route::patch('settings/read-receipts', [ReadReceiptsController::class, 'update'])->name('read-receipts.update');

    Route::patch('settings/sidebar-position', [SidebarPositionController::class, 'update'])->name('sidebar-position.update');

    Route::get('settings/language', [LocaleController::class, 'edit'])->name('locale.edit');
    Route::patch('settings/language', [LocaleController::class, 'update'])->name('locale.update');

    // Human personal access tokens for the public REST API. JSON endpoints only
    // (the settings UI ships in a follow-up); the whole surface 404s when the
    // integrations platform is disabled.
    Route::middleware('integrations')->group(function (): void {
        Route::get('settings/personal-access-tokens', [PersonalAccessTokenController::class, 'index'])
            ->name('personal-access-tokens.index');
        Route::post('settings/personal-access-tokens', [PersonalAccessTokenController::class, 'store'])
            ->name('personal-access-tokens.store');
        Route::delete('settings/personal-access-tokens/{token}', [PersonalAccessTokenController::class, 'destroy'])
            ->name('personal-access-tokens.destroy');
    });

    Route::get('settings/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('settings/teams', [TeamController::class, 'store'])->name('teams.store');

    Route::middleware(EnsureTeamMembership::class)->group(function (): void {
        Route::get('settings/teams/{team}', [TeamController::class, 'edit'])->name('teams.edit');
        Route::patch('settings/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('settings/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
        Route::post('settings/teams/{team}/switch', [TeamController::class, 'switch'])->name('teams.switch');
        Route::delete('settings/teams/{team}/leave', [TeamController::class, 'leave'])->name('teams.leave');

        Route::get('settings/teams/{team}/audit', [AuditController::class, 'index'])->name('teams.audit.index');

        Route::get('settings/teams/{team}/security-log', [SecurityLogController::class, 'index'])->name('teams.security-log.index');

        Route::get('settings/teams/{team}/exports', [AuditExportController::class, 'index'])->name('teams.audit-exports.index');
        Route::post('settings/teams/{team}/exports', [AuditExportController::class, 'store'])->name('teams.audit-exports.store');
        Route::get('settings/teams/{team}/exports/{auditExport}/download', [AuditExportController::class, 'download'])
            ->name('teams.audit-exports.download');

        Route::get('settings/teams/{team}/analytics', [AnalyticsController::class, 'index'])->name('teams.analytics.index');

        Route::get('settings/teams/{team}/emojis', [CustomEmojiController::class, 'index'])->name('teams.emojis.index');
        Route::post('settings/teams/{team}/emojis', [CustomEmojiController::class, 'store'])->name('teams.emojis.store');
        Route::delete('settings/teams/{team}/emojis/{emoji}', [CustomEmojiController::class, 'destroy'])
            ->name('teams.emojis.destroy');

        Route::get('settings/teams/{team}/groups', [UserGroupController::class, 'index'])->name('teams.groups.index');
        Route::post('settings/teams/{team}/groups', [UserGroupController::class, 'store'])->name('teams.groups.store');
        Route::patch('settings/teams/{team}/groups/{group}', [UserGroupController::class, 'update'])
            ->name('teams.groups.update');
        Route::delete('settings/teams/{team}/groups/{group}', [UserGroupController::class, 'destroy'])
            ->name('teams.groups.destroy');
        Route::post('settings/teams/{team}/groups/{group}/members', [UserGroupController::class, 'storeMember'])
            ->name('teams.groups.members.store');
        Route::delete('settings/teams/{team}/groups/{group}/members/{user}', [UserGroupController::class, 'destroyMember'])
            ->name('teams.groups.members.destroy');

        Route::get('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'show'])->name('teams.members.show');
        Route::get('settings/teams/{team}/members/{user}/card', [TeamMemberController::class, 'card'])->name('teams.members.card');
        Route::patch('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::post('settings/teams/{team}/members/{user}/transfer-ownership', [TeamMemberController::class, 'transferOwnership'])->name('teams.members.transfer-ownership');
        Route::delete('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

        Route::post('settings/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
        Route::delete('settings/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');
        Route::post('settings/teams/{team}/invitations/{invitation}/resend', [TeamInvitationController::class, 'resend'])->name('teams.invitations.resend');

        // The integrations management surface (bots, API tokens, incoming and
        // outgoing webhooks). The whole group additionally 404s when the
        // integrations platform is disabled, so the settings surface disappears
        // in lockstep with the API.
        Route::middleware('integrations')->group(function (): void {
            Route::get('settings/teams/{team}/integrations', [IntegrationsController::class, 'index'])
                ->name('teams.integrations.index');

            Route::post('settings/teams/{team}/integrations/bots', [BotController::class, 'store'])
                ->name('teams.integrations.bots.store');
            Route::get('settings/teams/{team}/integrations/bots/{bot}', [BotController::class, 'show'])
                ->name('teams.integrations.bots.show');
            Route::delete('settings/teams/{team}/integrations/bots/{bot}', [BotController::class, 'destroy'])
                ->name('teams.integrations.bots.destroy');

            Route::post('settings/teams/{team}/integrations/bots/{bot}/channels', [BotChannelController::class, 'store'])
                ->name('teams.integrations.bots.channels.store');
            Route::delete('settings/teams/{team}/integrations/bots/{bot}/channels/{channel:id}', [BotChannelController::class, 'destroy'])
                ->name('teams.integrations.bots.channels.destroy');

            Route::post('settings/teams/{team}/integrations/bots/{bot}/tokens', [BotTokenController::class, 'store'])
                ->name('teams.integrations.bots.tokens.store');
            Route::delete('settings/teams/{team}/integrations/bots/{bot}/tokens/{token}', [BotTokenController::class, 'destroy'])
                ->name('teams.integrations.bots.tokens.destroy');

            Route::post('settings/teams/{team}/integrations/incoming-webhooks', [IncomingWebhookController::class, 'store'])
                ->name('teams.integrations.incoming-webhooks.store');
            Route::delete('settings/teams/{team}/integrations/incoming-webhooks/{incomingWebhook}', [IncomingWebhookController::class, 'destroy'])
                ->name('teams.integrations.incoming-webhooks.destroy');

            Route::post('settings/teams/{team}/integrations/webhooks', [WebhookSubscriptionController::class, 'store'])
                ->name('teams.integrations.webhooks.store');
            Route::get('settings/teams/{team}/integrations/webhooks/{webhookSubscription}', [WebhookSubscriptionController::class, 'show'])
                ->name('teams.integrations.webhooks.show');
            Route::delete('settings/teams/{team}/integrations/webhooks/{webhookSubscription}', [WebhookSubscriptionController::class, 'destroy'])
                ->name('teams.integrations.webhooks.destroy');
            Route::post('settings/teams/{team}/integrations/webhooks/{webhookSubscription}/reenable', [WebhookSubscriptionController::class, 'reenable'])
                ->name('teams.integrations.webhooks.reenable');
            Route::post('settings/teams/{team}/integrations/webhooks/{webhookSubscription}/rotate-secret', [WebhookSubscriptionController::class, 'rotateSecret'])
                ->name('teams.integrations.webhooks.rotate-secret');
        });
    });
});
