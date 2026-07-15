<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\AboutController;
use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\DataExportController;
use App\Http\Controllers\Settings\LocaleController;
use App\Http\Controllers\Settings\NotificationController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\ReadReceiptsController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SessionController;
use App\Http\Controllers\Settings\TimezoneController;
use App\Http\Controllers\Teams\AnalyticsController;
use App\Http\Controllers\Teams\AuditController;
use App\Http\Controllers\Teams\CustomEmojiController;
use App\Http\Controllers\Teams\SecurityLogController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\Teams\TeamMemberController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

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

    Route::get('settings/language', [LocaleController::class, 'edit'])->name('locale.edit');
    Route::patch('settings/language', [LocaleController::class, 'update'])->name('locale.update');

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

        Route::get('settings/teams/{team}/analytics', [AnalyticsController::class, 'index'])->name('teams.analytics.index');

        Route::get('settings/teams/{team}/emojis', [CustomEmojiController::class, 'index'])->name('teams.emojis.index');
        Route::post('settings/teams/{team}/emojis', [CustomEmojiController::class, 'store'])->name('teams.emojis.store');
        Route::delete('settings/teams/{team}/emojis/{emoji}', [CustomEmojiController::class, 'destroy'])
            ->name('teams.emojis.destroy');

        Route::get('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'show'])->name('teams.members.show');
        Route::get('settings/teams/{team}/members/{user}/card', [TeamMemberController::class, 'card'])->name('teams.members.card');
        Route::patch('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
        Route::post('settings/teams/{team}/members/{user}/transfer-ownership', [TeamMemberController::class, 'transferOwnership'])->name('teams.members.transfer-ownership');
        Route::delete('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

        Route::post('settings/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
        Route::delete('settings/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');
        Route::post('settings/teams/{team}/invitations/{invitation}/resend', [TeamInvitationController::class, 'resend'])->name('teams.invitations.resend');
    });
});
