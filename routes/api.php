<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ChannelController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\ReactionController;
use App\Http\Controllers\Api\V1\WebhookSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public REST API v1
|--------------------------------------------------------------------------
|
| URI-versioned under /api/v1, authenticated by hashed Sanctum bot tokens and
| gated by the INTEGRATIONS_ENABLED toggle (the whole surface 404s when off).
| Every route names the single resource:action scope it requires, and the group
| is throttled per token at INTEGRATIONS_API_RATE_LIMIT requests/minute.
|
*/

Route::prefix('v1')
    ->as('api.v1.')
    ->middleware(['integrations', 'auth:sanctum', 'throttle:integrations'])
    ->group(function (): void {
        // Channels
        Route::get('channels', [ChannelController::class, 'index'])
            ->middleware('scope:channels:read')->name('channels.index');
        Route::post('channels', [ChannelController::class, 'store'])
            ->middleware('scope:channels:write')->name('channels.store');
        Route::get('channels/{channel:id}', [ChannelController::class, 'show'])
            ->middleware('scope:channels:read')->name('channels.show');
        Route::post('channels/{channel:id}/archive', [ChannelController::class, 'archive'])
            ->middleware('scope:channels:write')->name('channels.archive');

        // Messages
        Route::get('channels/{channel:id}/messages', [MessageController::class, 'index'])
            ->middleware('scope:messages:read')->name('messages.index');
        Route::post('channels/{channel:id}/messages', [MessageController::class, 'store'])
            ->middleware('scope:messages:write')->name('messages.store');
        Route::get('channels/{channel:id}/messages/{message}', [MessageController::class, 'show'])
            ->middleware('scope:messages:read')->name('messages.show');
        Route::patch('channels/{channel:id}/messages/{message}', [MessageController::class, 'update'])
            ->middleware('scope:messages:write')->name('messages.update');
        Route::delete('channels/{channel:id}/messages/{message}', [MessageController::class, 'destroy'])
            ->middleware('scope:messages:write')->name('messages.destroy');

        // Reactions (explicit add / remove, idempotent)
        Route::put('channels/{channel:id}/messages/{message}/reactions/{emoji}', [ReactionController::class, 'store'])
            ->middleware('scope:reactions:write')->name('reactions.store');
        Route::delete('channels/{channel:id}/messages/{message}/reactions/{emoji}', [ReactionController::class, 'destroy'])
            ->middleware('scope:reactions:write')->name('reactions.destroy');

        // Members
        Route::get('channels/{channel:id}/members', [MemberController::class, 'index'])
            ->middleware('scope:members:read')->name('members.index');
        Route::post('channels/{channel:id}/members', [MemberController::class, 'store'])
            ->middleware('scope:members:write')->name('members.store');
        Route::delete('channels/{channel:id}/members/{user}', [MemberController::class, 'destroy'])
            ->middleware('scope:members:write')->name('members.destroy');

        // Outgoing webhooks — subscription management (delivery itself is queued
        // and signed; see App\Jobs\DeliverWebhook).
        Route::get('webhooks', [WebhookSubscriptionController::class, 'index'])
            ->middleware('scope:webhooks:read')->name('webhooks.index');
        Route::post('webhooks', [WebhookSubscriptionController::class, 'store'])
            ->middleware('scope:webhooks:write')->name('webhooks.store');
        Route::get('webhooks/{webhookSubscription:id}', [WebhookSubscriptionController::class, 'show'])
            ->middleware('scope:webhooks:read')->name('webhooks.show');
        Route::delete('webhooks/{webhookSubscription:id}', [WebhookSubscriptionController::class, 'destroy'])
            ->middleware('scope:webhooks:write')->name('webhooks.destroy');
    });
