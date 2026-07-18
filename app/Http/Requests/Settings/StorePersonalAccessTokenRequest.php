<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\IntegrationScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a human minting a personal access token in their own settings: a
 * name, the one team the token is bound to (which must be a team they belong
 * to), and a least-privilege set of {@see IntegrationScope} abilities.
 */
class StorePersonalAccessTokenRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'team_id' => [
                'required',
                'uuid',
                Rule::exists('team_members', 'team_id')->where('user_id', $this->user()?->id),
            ],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(IntegrationScope::values())],
        ];
    }
}
