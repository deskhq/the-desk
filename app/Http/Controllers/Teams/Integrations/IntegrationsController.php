<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams\Integrations;

use App\Data\BotData;
use App\Data\IncomingWebhookData;
use App\Data\WebhookSubscriptionData;
use App\Enums\IntegrationScope;
use App\Enums\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationsController extends Controller
{
    /**
     * Show the team's integrations home — its bots, incoming webhooks, and
     * outgoing subscriptions, plus the options the create forms need.
     */
    public function index(Request $request, Team $team): Response
    {
        Gate::authorize('manageIntegrations', $team);

        return Inertia::render('teams/integrations/Index', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
            ],
            'bots' => BotData::forTeam($team),
            'incomingWebhooks' => IncomingWebhookData::forTeam($team),
            'outgoingWebhooks' => WebhookSubscriptionData::forTeam($team),
            'channels' => $this->channelOptions($team),
            'scopeOptions' => IntegrationScope::options(),
            'eventOptions' => WebhookEvent::options(),
        ]);
    }

    /**
     * The team's channels as id/name options for the create forms.
     *
     * @return array<int, array{id: string, name: string}>
     */
    private function channelOptions(Team $team): array
    {
        return $team->channels()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Channel $channel): array => ['id' => $channel->id, 'name' => $channel->name])
            ->all();
    }
}
