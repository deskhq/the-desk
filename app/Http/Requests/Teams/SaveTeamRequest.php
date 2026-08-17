<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams;

use App\Enums\ChannelCreationPolicy;
use App\Rules\TeamName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveTeamRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Creating a workspace only names it. Editing one is a PATCH split across
     * several independent forms on the admin page — the name, the
     * channel-creation policies — so every field is `sometimes` there and a form
     * only ever submits, and only ever changes, its own.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $name = ['required', 'string', 'max:255', new TeamName];

        if (! $this->isEditingExistingTeam()) {
            return ['name' => $name];
        }

        return [
            'name' => ['sometimes', ...$name],
            'public_channel_creation_policy' => ['sometimes', Rule::enum(ChannelCreationPolicy::class)],
            'private_channel_creation_policy' => ['sometimes', Rule::enum(ChannelCreationPolicy::class)],
        ];
    }

    /**
     * Whether this request edits a workspace that already exists, rather than
     * creating one. The route model is the distinction: only the update route
     * binds a team.
     */
    private function isEditingExistingTeam(): bool
    {
        return $this->route('team') !== null;
    }
}
