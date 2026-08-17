<?php

declare(strict_types=1);

namespace App\Http\Requests\Teams;

use App\Rules\ValidTeamInvitation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class RespondToTeamInvitationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'invitation' => ['required', new ValidTeamInvitation($this->user())],
        ];
    }

    /**
     * Get the validation data from the request.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'invitation' => $this->route('invitation'),
        ]);
    }
}
