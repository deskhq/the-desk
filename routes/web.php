<?php

use App\Http\Controllers\Auth\Sso\OidcController;
use App\Http\Controllers\Channels\AttachmentController;
use App\Http\Controllers\Channels\ChannelController;
use App\Http\Controllers\Channels\ChannelDraftController;
use App\Http\Controllers\Channels\ChannelMemberController;
use App\Http\Controllers\Channels\ChannelPlacementController;
use App\Http\Controllers\Channels\ChannelPreferenceController;
use App\Http\Controllers\Channels\ChannelSectionController;
use App\Http\Controllers\Channels\ChannelStarController;
use App\Http\Controllers\Channels\DirectMessageController;
use App\Http\Controllers\Channels\DirectMessagePeopleController;
use App\Http\Controllers\Channels\ForwardMessageController;
use App\Http\Controllers\Channels\HideDirectMessageController;
use App\Http\Controllers\Channels\MessageController;
use App\Http\Controllers\Channels\MessageReminderController;
use App\Http\Controllers\Channels\PinController;
use App\Http\Controllers\Channels\ReactionController;
use App\Http\Controllers\Channels\ScheduledMessageController;
use App\Http\Controllers\Channels\SearchController;
use App\Http\Controllers\Channels\ThreadsController;
use App\Http\Controllers\LocaleCatalogController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\SidebarSectionController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// OpenID Connect single sign-on. The routes are always registered (so Wayfinder
// can generate them and the login page can link to them); the controller 404s
// when no provider is configured, and the login page hides the entry point to
// match. A full-page GET redirect (not an Inertia visit) hands off to the IdP.
Route::get('auth/oidc/redirect', [OidcController::class, 'redirect'])->name('sso.oidc.redirect');
Route::get('auth/oidc/callback', [OidcController::class, 'callback'])->name('sso.oidc.callback');

Route::get('locales/{locale}.json', [LocaleCatalogController::class, 'show'])
    ->where('locale', '[a-z]{2}')
    ->name('locales.show');

Route::middleware(['auth', 'verified', EnsureTeamMembership::class])->group(function (): void {
    Route::get('t/{team}', [ChannelController::class, 'index'])->name('channels.index');
    Route::post('t/{team}/channels', [ChannelController::class, 'store'])->name('channels.store');
    Route::post('t/{team}/dm', [DirectMessageController::class, 'store'])->name('channels.dm.store');
    Route::post('t/{team}/c/{channel}/dm/people', [DirectMessagePeopleController::class, 'store'])
        ->scopeBindings()
        ->name('channels.dm.people.store');
    Route::post('t/{team}/c/{channel}/hide', [HideDirectMessageController::class, 'store'])
        ->scopeBindings()
        ->name('channels.dm.hide');
    Route::get('t/{team}/channels/browse', [ChannelController::class, 'browse'])->name('channels.browse');
    Route::get('t/{team}/search', [SearchController::class, 'index'])->name('search');
    Route::get('t/{team}/search/suggest', [SearchController::class, 'suggest'])->name('search.suggest');
    Route::get('t/{team}/threads', [ThreadsController::class, 'index'])->name('channels.threads.index');
    Route::get('t/{team}/c/{channel}', [ChannelController::class, 'show'])
        ->scopeBindings()
        ->name('channels.show');
    Route::post('t/{team}/c/{channel}/join', [ChannelController::class, 'join'])
        ->scopeBindings()
        ->name('channels.join');
    Route::post('t/{team}/c/{channel}/leave', [ChannelController::class, 'leave'])
        ->scopeBindings()
        ->name('channels.leave');
    Route::post('t/{team}/c/{channel}/read', [ChannelController::class, 'read'])
        ->scopeBindings()
        ->name('channels.read');
    Route::post('t/{team}/c/{channel}/threads/{message}/read', [ChannelController::class, 'readThread'])
        ->scopeBindings()
        ->withTrashed()
        ->name('channels.threads.read');
    Route::patch('t/{team}/c/{channel}/preferences', [ChannelPreferenceController::class, 'update'])
        ->scopeBindings()
        ->name('channels.preferences.update');
    Route::patch('t/{team}/c/{channel}/draft', [ChannelDraftController::class, 'update'])
        ->scopeBindings()
        ->name('channels.draft.update');
    Route::patch('t/{team}/c/{channel}/star', [ChannelStarController::class, 'update'])
        ->scopeBindings()
        ->name('channels.star.update');
    Route::patch('t/{team}/c/{channel}/placement', [ChannelPlacementController::class, 'update'])
        ->scopeBindings()
        ->name('channels.placement.update');
    Route::post('t/{team}/sidebar/sections', [ChannelSectionController::class, 'store'])->name('channels.sections.store');
    Route::patch('t/{team}/sidebar/sections/reorder', [ChannelSectionController::class, 'reorder'])->name('channels.sections.reorder');
    Route::patch('t/{team}/sidebar/sections/{section}', [ChannelSectionController::class, 'update'])->name('channels.sections.update');
    Route::delete('t/{team}/sidebar/sections/{section}', [ChannelSectionController::class, 'destroy'])->name('channels.sections.destroy');
    Route::post('t/{team}/c/{channel}/archive', [ChannelController::class, 'archive'])
        ->scopeBindings()
        ->name('channels.archive');
    Route::post('t/{team}/c/{channel}/messages', [MessageController::class, 'store'])
        ->scopeBindings()
        ->name('channels.messages.store');
    Route::patch('t/{team}/c/{channel}/messages/{message}', [MessageController::class, 'update'])
        ->scopeBindings()
        ->name('channels.messages.update');
    Route::delete('t/{team}/c/{channel}/messages/{message}', [MessageController::class, 'destroy'])
        ->scopeBindings()
        ->name('channels.messages.destroy');
    Route::post('t/{team}/c/{channel}/messages/{message}/forward', [ForwardMessageController::class, 'store'])
        ->scopeBindings()
        ->name('channels.messages.forward');
    Route::post('t/{team}/c/{channel}/attachments', [AttachmentController::class, 'store'])
        ->scopeBindings()
        ->name('channels.attachments.store');
    Route::get('t/{team}/c/{channel}/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->scopeBindings()
        ->name('channels.attachments.download');
    Route::get('t/{team}/c/{channel}/attachments/{attachment}/thumbnail', [AttachmentController::class, 'thumbnail'])
        ->scopeBindings()
        ->name('channels.attachments.thumbnail');
    Route::post('t/{team}/c/{channel}/scheduled-messages', [ScheduledMessageController::class, 'store'])
        ->scopeBindings()
        ->name('channels.scheduled-messages.store');
    Route::patch('t/{team}/c/{channel}/scheduled-messages/{scheduledMessage}', [ScheduledMessageController::class, 'update'])
        ->scopeBindings()
        ->name('channels.scheduled-messages.update');
    Route::delete('t/{team}/c/{channel}/scheduled-messages/{scheduledMessage}', [ScheduledMessageController::class, 'destroy'])
        ->scopeBindings()
        ->name('channels.scheduled-messages.destroy');
    Route::post('t/{team}/reminders', [MessageReminderController::class, 'store'])
        ->name('channels.reminders.store');
    Route::delete('t/{team}/reminders', [MessageReminderController::class, 'destroyAll'])
        ->name('channels.reminders.clear');
    Route::delete('t/{team}/reminders/{reminder}', [MessageReminderController::class, 'destroy'])
        ->name('channels.reminders.destroy');
    Route::post('t/{team}/c/{channel}/messages/{message}/reactions', [ReactionController::class, 'store'])
        ->scopeBindings()
        ->name('channels.messages.reactions.store');
    Route::post('t/{team}/c/{channel}/messages/{message}/pin', [PinController::class, 'store'])
        ->scopeBindings()
        ->name('channels.messages.pin.store');
    Route::delete('t/{team}/c/{channel}/messages/{message}/pin', [PinController::class, 'destroy'])
        ->scopeBindings()
        ->name('channels.messages.pin.destroy');
    Route::post('t/{team}/c/{channel}/members', [ChannelMemberController::class, 'store'])
        ->scopeBindings()
        ->name('channels.members.store');
    Route::delete('t/{team}/c/{channel}/members', [ChannelMemberController::class, 'destroy'])
        ->scopeBindings()
        ->name('channels.members.destroy');
});

Route::middleware(['auth'])->group(function (): void {
    Route::patch('sidebar/sections', [SidebarSectionController::class, 'update'])->name('sidebar.sections.update');

    Route::patch('onboarding', [OnboardingController::class, 'update'])->name('onboarding.update');

    Route::get('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
