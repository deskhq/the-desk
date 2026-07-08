<?php

use App\Http\Controllers\Channels\ChannelController;
use App\Http\Controllers\Channels\ChannelMemberController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
    });

Route::middleware(['auth', 'verified', EnsureTeamMembership::class])->group(function () {
    Route::get('t/{team}', [ChannelController::class, 'index'])->name('channels.index');
    Route::post('t/{team}/channels', [ChannelController::class, 'store'])->name('channels.store');
    Route::get('t/{team}/channels/browse', [ChannelController::class, 'browse'])->name('channels.browse');
    Route::get('t/{team}/c/{channel}', [ChannelController::class, 'show'])
        ->scopeBindings()
        ->name('channels.show');
    Route::post('t/{team}/c/{channel}/join', [ChannelController::class, 'join'])
        ->scopeBindings()
        ->name('channels.join');
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
