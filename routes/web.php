<?php

use App\Http\Controllers\Channels\ChannelController;
use App\Http\Controllers\Channels\ChannelMemberController;
use App\Http\Controllers\Channels\ChannelPreferenceController;
use App\Http\Controllers\Channels\MessageController;
use App\Http\Controllers\Channels\SearchController;
use App\Http\Controllers\Channels\ThreadsController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified', EnsureTeamMembership::class])->group(function () {
    Route::get('t/{team}', [ChannelController::class, 'index'])->name('channels.index');
    Route::post('t/{team}/channels', [ChannelController::class, 'store'])->name('channels.store');
    Route::get('t/{team}/channels/browse', [ChannelController::class, 'browse'])->name('channels.browse');
    Route::get('t/{team}/search', [SearchController::class, 'index'])->name('search');
    Route::get('t/{team}/threads', [ThreadsController::class, 'index'])->name('channels.threads.index');
    Route::get('t/{team}/c/{channel}', [ChannelController::class, 'show'])
        ->scopeBindings()
        ->name('channels.show');
    Route::post('t/{team}/c/{channel}/join', [ChannelController::class, 'join'])
        ->scopeBindings()
        ->name('channels.join');
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
    Route::post('t/{team}/c/{channel}/members', [ChannelMemberController::class, 'store'])
        ->scopeBindings()
        ->name('channels.members.store');
    Route::delete('t/{team}/c/{channel}/members', [ChannelMemberController::class, 'destroy'])
        ->scopeBindings()
        ->name('channels.members.destroy');
});

Route::middleware(['auth'])->group(function () {
    Route::get('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
