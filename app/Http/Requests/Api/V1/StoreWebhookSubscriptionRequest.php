<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\WebhookEvent;
use App\Models\Channel;
use App\Models\Team;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a bot registering an outgoing-webhook subscription in its own team
 * via the public API.
 */
class StoreWebhookSubscriptionRequest extends ApiRequest
{
    /**
     * A bot may only subscribe within the team it is scoped to.
     */
    public function authorize(): bool
    {
        return $this->bot()->owner_team_id !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookEvent::values())],
            'channel_ids' => ['nullable', 'array'],
            'channel_ids.*' => ['string'],
        ];
    }

    /**
     * Every listed channel must belong to the bot's team, so a subscription can
     * only ever be scoped to channels its team owns.
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
                    $validator->errors()->add('channel_ids', __('One or more channels are not in your workspace.'));
                }
            },
        ];
    }

    /**
     * The team the subscription belongs to — the bot's own team.
     */
    public function team(): Team
    {
        return $this->bot()->ownerTeam()->firstOrFail();
    }
}
