<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams\Integrations;

use App\Enums\WebhookEvent;
use App\Models\Channel;
use App\Models\Team;
use App\Rules\PublicWebhookUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates registering an outgoing-webhook subscription from the settings
 * surface. An optional channel allow-list narrows delivery; every listed channel
 * must belong to the team (omit the list to receive events from every channel).
 */
class StoreWebhookSubscriptionRequest extends FormRequest
{
    /**
     * Only integration managers (Owner + Admin) may register subscriptions.
     */
    public function authorize(): bool
    {
        return Gate::allows('manageIntegrations', $this->team());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048', new PublicWebhookUrl],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookEvent::values())],
            'channel_ids' => ['nullable', 'array'],
            'channel_ids.*' => ['string'],
        ];
    }

    /**
     * Every listed channel must belong to the team.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $channelIds = $this->input('channel_ids');

                if (! is_array($channelIds) || $channelIds === []) {
                    return;
                }

                $ownedCount = Channel::query()
                    ->where('team_id', $this->team()->id)
                    ->whereIn('id', $channelIds)
                    ->count();

                if ($ownedCount !== count(array_unique($channelIds))) {
                    $validator->errors()->add('channel_ids', __('One or more channels are not in this workspace.'));
                }
            },
        ];
    }

    /**
     * The channel allow-list, or null when the subscription listens everywhere.
     *
     * @return list<string>|null
     */
    public function channelIds(): ?array
    {
        $channelIds = $this->validated('channel_ids');

        if (! is_array($channelIds) || $channelIds === []) {
            return null;
        }

        return array_values(array_unique($channelIds));
    }

    /**
     * The team the subscription belongs to.
     */
    public function team(): Team
    {
        $team = $this->route('team');

        abort_if(! $team instanceof Team, 404);

        return $team;
    }
}
