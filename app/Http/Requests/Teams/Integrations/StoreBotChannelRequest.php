<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams\Integrations;

use App\Enums\ChannelType;
use App\Http\Requests\RouteBoundRequest;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Validates adding a bot to one of the team's channels from the integrations
 * surface. The bot may join any standard channel in its own team (public or
 * private) — direct messages are never a valid target.
 */
class StoreBotChannelRequest extends RouteBoundRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'channel_id' => [
                'required',
                'uuid',
                Rule::exists('channels', 'id')
                    ->where('team_id', $this->team()->id)
                    ->where('type', ChannelType::Standard->value),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->bot()->channels()->whereKey($value)->exists()) {
                        $fail(__('The bot is already in this channel.'));
                    }
                },
            ],
        ];
    }

    /**
     * The bot whose channel membership is being changed.
     */
    public function bot(): User
    {
        return $this->routeModel('bot', User::class);
    }
}
