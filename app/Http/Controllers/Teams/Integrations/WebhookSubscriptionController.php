<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams\Integrations;

use App\Actions\Integrations\CreateWebhookSubscription;
use App\Actions\Integrations\ReenableWebhookSubscription;
use App\Actions\Integrations\ReplayWebhookDelivery;
use App\Actions\Integrations\RevokeWebhookSubscription;
use App\Actions\Integrations\RotateWebhookSecret;
use App\Data\WebhookSubscriptionDetailData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\Integrations\StoreWebhookSubscriptionRequest;
use App\Models\Team;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WebhookSubscriptionController extends Controller
{
    /**
     * Register an outgoing subscription and reveal its signing secret once.
     */
    public function store(StoreWebhookSubscriptionRequest $request, Team $team, CreateWebhookSubscription $create): RedirectResponse
    {
        $subscription = $create->handle(
            $team,
            $request->user(),
            $request->validated('name'),
            $request->validated('url'),
            array_values($request->validated('events')),
            $request->channelIds(),
        );

        Inertia::flash('revealed', [
            'kind' => 'webhook_secret',
            'label' => $subscription->name,
            'value' => $subscription->secret,
        ]);

        return back();
    }

    /**
     * Show a subscription's detail — its health, delivery log, and secret controls.
     */
    public function show(Request $request, Team $team, WebhookSubscription $webhookSubscription): Response
    {
        return Inertia::render('teams/integrations/Webhook', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'detail' => WebhookSubscriptionDetailData::fromModel($webhookSubscription),
        ]);
    }

    /**
     * Revoke a subscription, stopping all future delivery immediately.
     */
    public function destroy(Request $request, Team $team, WebhookSubscription $webhookSubscription, RevokeWebhookSubscription $revoke): RedirectResponse
    {
        $revoke->handle($request->user(), $webhookSubscription);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscription revoked')]);

        return to_route('teams.integrations.index', ['team' => $team->slug]);
    }

    /**
     * Re-enable an auto-disabled subscription, clearing its failure streak.
     */
    public function reenable(Request $request, Team $team, WebhookSubscription $webhookSubscription, ReenableWebhookSubscription $reenable): RedirectResponse
    {
        $reenable->handle($request->user(), $webhookSubscription);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscription re-enabled')]);

        return back();
    }

    /**
     * Re-fire one past delivery attempt against the endpoint.
     *
     * The replay is a single shot that leaves the subscription's health alone,
     * so it is offered on a disabled subscription too — verifying a fixed
     * endpoint is exactly what it is for.
     */
    public function replay(Request $request, Team $team, WebhookSubscription $webhookSubscription, WebhookDelivery $delivery, ReplayWebhookDelivery $replay): RedirectResponse
    {
        abort_unless($delivery->isReplayable(), 422, __('This delivery was logged before payloads were retained, so it cannot be replayed.'));

        $replay->handle($request->user(), $delivery);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Delivery queued for replay')]);

        return back();
    }

    /**
     * Rotate a subscription's signing secret and reveal the new value once.
     */
    public function rotateSecret(Request $request, Team $team, WebhookSubscription $webhookSubscription, RotateWebhookSecret $rotate): RedirectResponse
    {
        $secret = $rotate->handle($request->user(), $webhookSubscription);

        Inertia::flash('revealed', [
            'kind' => 'webhook_secret',
            'label' => $webhookSubscription->name,
            'value' => $secret,
        ]);

        return back();
    }
}
