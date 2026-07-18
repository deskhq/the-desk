<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams\Integrations;

use App\Actions\Integrations\CreateIncomingWebhook;
use App\Actions\Integrations\RevokeIncomingWebhook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\Integrations\StoreIncomingWebhookRequest;
use App\Models\Channel;
use App\Models\IncomingWebhook;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class IncomingWebhookController extends Controller
{
    /**
     * Create an incoming webhook and reveal its opaque URL once.
     */
    public function store(StoreIncomingWebhookRequest $request, Team $team, CreateIncomingWebhook $create): RedirectResponse
    {
        /** @var User $bot */
        $bot = $team->bots()->whereKey($request->validated('bot_id'))->firstOrFail();
        /** @var Channel $channel */
        $channel = $team->channels()->whereKey($request->validated('channel_id'))->firstOrFail();

        $webhook = $create->handle(
            $bot,
            $channel,
            $request->user(),
            $request->validated('name'),
            (bool) $request->validated('with_signing_secret', false),
        );

        Inertia::flash('revealed', [
            'kind' => 'incoming_webhook',
            'label' => $request->validated('name'),
            'value' => $webhook->url(),
            'signingSecret' => $webhook->signingSecret,
        ]);

        return back();
    }

    /**
     * Revoke an incoming webhook immediately.
     */
    public function destroy(Request $request, Team $team, IncomingWebhook $incomingWebhook, RevokeIncomingWebhook $revoke): RedirectResponse
    {
        Gate::authorize('manageIntegrations', $team);

        abort_unless($incomingWebhook->team_id === $team->id, 404);

        $revoke->handle($request->user(), $incomingWebhook);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Incoming webhook revoked.')]);

        return back();
    }
}
