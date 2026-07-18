<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams\Integrations;

use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validates creating an incoming webhook (a secret URL that posts into one
 * channel as a bot) from the settings surface. The channel and bot must both
 * belong to the team; the action re-checks that the bot can post to the channel.
 */
class StoreIncomingWebhookRequest extends FormRequest
{
    /**
     * Only integration managers (Owner + Admin) may create incoming webhooks.
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
        $team = $this->team();

        return [
            'name' => ['required', 'string', 'max:255'],
            'channel_id' => [
                'required',
                'string',
                Rule::exists('channels', 'id')->where('team_id', $team->id),
            ],
            'bot_id' => [
                'required',
                'string',
                Rule::exists('users', 'id')
                    ->where('owner_team_id', $team->id)
                    ->where('type', 'bot'),
            ],
            'with_signing_secret' => ['boolean'],
        ];
    }

    /**
     * The team the webhook belongs to.
     */
    public function team(): Team
    {
        $team = $this->route('team');

        abort_if(! $team instanceof Team, 404);

        return $team;
    }
}
