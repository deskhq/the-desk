<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Integrations\CreateWebhookSubscription;
use App\Actions\Integrations\RevokeWebhookSubscription;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreWebhookSubscriptionRequest;
use App\Http\Resources\Api\V1\WebhookSubscriptionResource;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WebhookSubscriptionController extends Controller
{
    /**
     * List the bot's team's webhook subscriptions, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $bot = $request->user();
        assert($bot instanceof User);

        $subscriptions = WebhookSubscription::query()
            ->where('team_id', $bot->owner_team_id)
            ->latest()
            ->get();

        return WebhookSubscriptionResource::collection($subscriptions);
    }

    /**
     * Register a webhook subscription in the bot's team.
     *
     * The signing secret is returned in plaintext alongside the resource exactly
     * once here; it is never exposed again, so the integrator must store it now.
     */
    public function store(StoreWebhookSubscriptionRequest $request, CreateWebhookSubscription $createWebhookSubscription): JsonResponse
    {
        $bot = $request->user();
        assert($bot instanceof User);

        /** @var list<string> $events */
        $events = $request->validated('events');
        /** @var list<string>|null $channelIds */
        $channelIds = $request->validated('channel_ids');

        $subscription = $createWebhookSubscription->handle(
            team: $request->team(),
            actor: $bot,
            name: $request->validated('name'),
            url: $request->validated('url'),
            events: $events,
            channelIds: $channelIds,
        );

        return WebhookSubscriptionResource::make($subscription)
            ->additional(['secret' => $subscription->secret])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a single subscription with its recent delivery attempts.
     */
    public function show(Request $request, WebhookSubscription $webhookSubscription): WebhookSubscriptionResource
    {
        $this->authorizeSubscription($request, $webhookSubscription);

        $webhookSubscription->load(['deliveries' => fn ($query) => $query->latest()->limit(20)]);

        return WebhookSubscriptionResource::make($webhookSubscription);
    }

    /**
     * Revoke (delete) a subscription, stopping all future delivery.
     */
    public function destroy(Request $request, WebhookSubscription $webhookSubscription, RevokeWebhookSubscription $revokeWebhookSubscription): JsonResponse
    {
        $bot = $this->authorizeSubscription($request, $webhookSubscription);

        $revokeWebhookSubscription->handle($bot, $webhookSubscription);

        return response()->json(null, 204);
    }

    /**
     * Ensure the subscription belongs to the authenticated bot's team, 404ing
     * otherwise so a bot can neither read nor revoke another team's subscription.
     */
    private function authorizeSubscription(Request $request, WebhookSubscription $subscription): User
    {
        $bot = $request->user();
        assert($bot instanceof User);

        abort_unless($subscription->team_id === $bot->owner_team_id, 404);

        return $bot;
    }
}
