<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams;

use App\Enums\TeamRole;
use App\Http\Requests\RouteBoundRequest;
use App\Rules\UniqueTeamInvitation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

final class CreateTeamInvitationRequest extends RouteBoundRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation($this->team())],
            'role' => ['required', 'string', Rule::enum(TeamRole::class)],
        ];
    }
}
