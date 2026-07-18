<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams\Integrations;

use App\Actions\Integrations\CreateWebhookSubscription;
use App\Actions\Integrations\ReenableWebhookSubscription;
use App\Actions\Integrations\RevokeWebhookSubscription;
use App\Actions\Integrations\RotateWebhookSecret;
use App\Data\WebhookSubscriptionDetailData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\Integrations\StoreWebhookSubscriptionRequest;
use App\Models\Team;
use App\Models\WebhookSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        Gate::authorize('manageIntegrations', $team);

        $this->ensureSubscriptionBelongsToTeam($webhookSubscription, $team);

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
        Gate::authorize('manageIntegrations', $team);

        $this->ensureSubscriptionBelongsToTeam($webhookSubscription, $team);

        $revoke->handle($request->user(), $webhookSubscription);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscription revoked.')]);

        return to_route('teams.integrations.index', ['team' => $team->slug]);
    }

    /**
     * Re-enable an auto-disabled subscription, clearing its failure streak.
     */
    public function reenable(Request $request, Team $team, WebhookSubscription $webhookSubscription, ReenableWebhookSubscription $reenable): RedirectResponse
    {
        Gate::authorize('manageIntegrations', $team);

        $this->ensureSubscriptionBelongsToTeam($webhookSubscription, $team);

        $reenable->handle($request->user(), $webhookSubscription);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscription re-enabled.')]);

        return back();
    }

    /**
     * Rotate a subscription's signing secret and reveal the new value once.
     */
    public function rotateSecret(Request $request, Team $team, WebhookSubscription $webhookSubscription, RotateWebhookSecret $rotate): RedirectResponse
    {
        Gate::authorize('manageIntegrations', $team);

        $this->ensureSubscriptionBelongsToTeam($webhookSubscription, $team);

        $secret = $rotate->handle($request->user(), $webhookSubscription);

        Inertia::flash('revealed', [
            'kind' => 'webhook_secret',
            'label' => $webhookSubscription->name,
            'value' => $secret,
        ]);

        return back();
    }

    /**
     * Guard that the subscription belongs to this team.
     */
    private function ensureSubscriptionBelongsToTeam(WebhookSubscription $subscription, Team $team): void
    {
        abort_unless($subscription->team_id === $team->id, 404);
    }
}
